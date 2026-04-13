<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use App\Services\FinancialInsightService;

class InsightsController extends Controller
{
    public function assistant(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $prompt = (string) $request->query('prompt', 'summary');
        $message = trim((string) $request->query('message', ''));
        if ($message !== '') {
            $prompt = 'free_text';
        }
        $now = CarbonImmutable::now('UTC');
        $from = $now->subDays(30);

        $recentTx = Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('date', '>=', $from)
            ->orderByDesc('date')
            ->get();

        $income = (float) $recentTx->where('type', 'income')->sum('amount');
        $expense = (float) $recentTx->where('type', 'expense')->sum('amount');
        $net = $income - $expense;

        $categories = Category::query()->pluck('name', 'idCategory');

        $expenseByCategory = [];
        foreach ($recentTx->where('type', 'expense') as $tx) {
            $catName = $categories[$tx->idCategory] ?? 'Uncategorized';
            $expenseByCategory[$catName] = ($expenseByCategory[$catName] ?? 0) + (float) $tx->amount;
        }

        arsort($expenseByCategory);
        $topCategory = array_key_first($expenseByCategory);
        $topCategoryAmount = $topCategory ? (float) $expenseByCategory[$topCategory] : 0.0;

        $avgDailyExpense = $expense / 30;
        $projectedMonthlyExpense = $avgDailyExpense * 30;

        $monthStart = $now->startOfMonth();
        $monthEnd = $monthStart->addMonth();
        $activeBudgets = Budget::query()
            ->where('idUser', $user->idUser)
            ->where('periodStart', '<', $monthEnd)
            ->where('periodEnd', '>=', $monthStart)
            ->get();

        $warningCount = 0;
        foreach ($activeBudgets as $budget) {
            $spent = (float) Transaction::query()
                ->where('idUser', $user->idUser)
                ->where('type', 'expense')
                ->when($budget->idCategory, fn ($q) => $q->where('idCategory', $budget->idCategory))
                ->whereBetween('date', [$budget->periodStart, $budget->periodEnd])
                ->sum('amount');

            $limit = (float) $budget->amount;
            if ($limit > 0 && ($spent / $limit) >= 0.8) {
                $warningCount++;
            }
        }

        $savingsRate = $income > 0 ? ($net / $income) * 100 : 0;

        $summary = [
            'periodDays' => 30,
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'net' => round($net, 2),
            'savingsRate' => round($savingsRate, 2),
            'topExpenseCategory' => $topCategory,
            'topExpenseAmount' => round($topCategoryAmount, 2),
            'projectedMonthlyExpense' => round($projectedMonthlyExpense, 2),
            'activeBudgetWarnings' => $warningCount,
        ];

        $assistantReply = $this->buildAssistantReply($prompt, $summary, $message);

        return response()->json([
            'summary' => $summary,
            'assistantReply' => $assistantReply,
            'quickPrompts' => [
                ['key' => 'summary', 'label' => 'Ringkas kondisi keuangan saya'],
                ['key' => 'saving_tips', 'label' => 'Kasih 3 strategi hemat minggu ini'],
                ['key' => 'what_to_cut', 'label' => 'Pengeluaran mana yang bisa dipangkas dulu'],
                ['key' => 'budget_alerts', 'label' => 'Budget mana yang paling rawan jebol'],
            ],
        ]);
    }

    public function receiptOcr(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'receiptImage' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('receiptImage');
        if ($file === null) {
            return response()->json(['message' => 'receiptImage is required'], 422);
        }

        $originalName = (string) $file->getClientOriginalName();
        $imageInfo = @getimagesize($file->getRealPath());
        $width = is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : 0;
        $height = is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : 0;

        $ocrText = '';
        $ocrLines = [];
        $ocrConfidence = 0.0;
        $engine = 'tesseract';

        try {
            $preprocessedPath = $this->preprocessReceiptImage($file);
            $ocrPayload = $this->runTesseractTsv($preprocessedPath);

            $ocrText = $ocrPayload['text'];
            $ocrLines = $ocrPayload['lines'];
            $ocrConfidence = $ocrPayload['confidence'];
            $engine = $ocrPayload['engine'];

            if (is_file($preprocessedPath)) {
                @unlink($preprocessedPath);
            }
        } catch (RuntimeException $error) {
            return response()->json([
                'message' => $error->getMessage(),
                'hint' => 'Install Tesseract OCR or set OCR_TESSERACT_BIN in backend .env',
            ], 503);
        }

        $parsed = $this->parseReceiptFields($ocrText, $ocrLines, $originalName);
        $merchant = $parsed['merchant'];
        $date = $parsed['date'];
        $total = $parsed['total'];
        $items = $parsed['items'];
        $parserScore = $parsed['parserScore'];
        $calculatedTotal = $parsed['calculatedItemsTotal'];
        $fieldConfidence = $parsed['fieldConfidence'];
        $merchantTemplate = $parsed['merchantTemplate'];

        $confidence = round(min(0.99, (($ocrConfidence / 100) * 0.7) + ($parserScore * 0.3)), 2);
        $difference = abs($calculatedTotal - $total);
        $validation = [
            'itemsTotal' => round($calculatedTotal, 2),
            'ocrTotal' => round($total, 2),
            'difference' => round($difference, 2),
            'isConsistent' => $difference <= max(1500.0, $total * 0.03),
        ];

        $categories = Category::query()
            ->where(function ($query) use ($user): void {
                $query->whereNull('idUser')->orWhere('idUser', $user->idUser);
            })
            ->orderBy('name')
            ->get(['idCategory', 'name']);

        $suggestedCategory = $categories
            ->first(function (Category $category) use ($merchant): bool {
                $name = strtolower((string) $category->name);
                $merchantText = strtolower($merchant);
                if (str_contains($merchantText, 'mart') || str_contains($merchantText, 'store')) {
                    return str_contains($name, 'grocer') || str_contains($name, 'belanja') || str_contains($name, 'makan');
                }

                return str_contains($merchantText, $name) || str_contains($name, $merchantText);
            });

        return response()->json([
            'merchant' => $merchant,
            'date' => $date,
            'total' => round((float) $total, 2),
            'currency' => 'IDR',
            'items' => $items,
            'meta' => [
                'filename' => $originalName,
                'imageWidth' => $width,
                'imageHeight' => $height,
                'confidence' => $confidence,
                'ocrConfidence' => round($ocrConfidence, 2),
                'engine' => $engine,
                'lineCount' => count($ocrLines),
                'rawText' => mb_substr($ocrText, 0, 2500),
                'validation' => $validation,
                'fieldConfidence' => $fieldConfidence,
                'merchantTemplate' => $merchantTemplate,
            ],
            'suggested' => [
                'type' => 'expense',
                'description' => 'Belanja di ' . $merchant,
                'source' => null,
                'idCategory' => $suggestedCategory?->idCategory,
                'categoryName' => $suggestedCategory?->name,
            ],
        ]);
    }

    private function preprocessReceiptImage(UploadedFile $file): string
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'receipt_ocr_');
        if ($tmpBase === false) {
            throw new RuntimeException('Failed to create temporary file for OCR preprocessing.');
        }

        $targetPath = $tmpBase . '.png';
        @unlink($tmpBase);

        $realPath = $file->getRealPath();
        if (!is_string($realPath) || !is_file($realPath)) {
            throw new RuntimeException('Uploaded image file is not readable.');
        }

        if (class_exists('Imagick')) {
            try {
                $imagick = new \Imagick($realPath);
                $imagick->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
                $imagick->autoOrient();
                $imagick->setImageColorspace(\Imagick::COLORSPACE_GRAY);
                $imagick->deskewImage(35);
                $imagick->contrastStretchImage(0.15, 0.3);
                $imagick->normalizeImage();
                $imagick->setImageFormat('png');
                $imagick->writeImage($targetPath);
                $imagick->clear();
                $imagick->destroy();

                if (is_file($targetPath)) {
                    return $targetPath;
                }
            } catch (\Throwable) {
                // Fallback to GD preprocessing when Imagick is unavailable or fails.
            }
        }

        if (!function_exists('imagecreatefromstring')) {
            if (!copy($realPath, $targetPath)) {
                throw new RuntimeException('Failed to copy uploaded image for OCR.');
            }

            return $targetPath;
        }

        $raw = file_get_contents($realPath);
        if ($raw === false) {
            throw new RuntimeException('Failed to read uploaded image for OCR.');
        }

        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            if (!copy($realPath, $targetPath)) {
                throw new RuntimeException('Failed to preprocess uploaded image.');
            }

            return $targetPath;
        }

        imagefilter($img, IMG_FILTER_GRAYSCALE);
        imagefilter($img, IMG_FILTER_CONTRAST, -25);
        imagefilter($img, IMG_FILTER_BRIGHTNESS, 8);

        $width = imagesx($img);
        $height = imagesy($img);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $gray = ($rgb >> 16) & 0xFF;
                imagesetpixel($img, $x, $y, $gray > 155 ? $white : $black);
            }
        }

        imagepng($img, $targetPath);
        imagedestroy($img);

        if (!is_file($targetPath)) {
            throw new RuntimeException('Failed to write preprocessed image for OCR.');
        }

        return $targetPath;
    }

    private function runTesseractTsv(string $imagePath): array
    {
        if (!function_exists('shell_exec')) {
            throw new RuntimeException('shell_exec is disabled. OCR engine cannot be executed.');
        }

        $binary = trim((string) config('services.ocr.tesseract_bin', 'tesseract'));
        $lang = preg_replace('/[^a-z+]/i', '', (string) config('services.ocr.language', 'ind+eng')) ?: 'ind+eng';
        $oem = (int) config('services.ocr.oem', 1);
        $psm = (int) config('services.ocr.psm', 6);
        $minConfidence = (float) config('services.ocr.min_word_confidence', 35);

        $binaryPart = preg_match('/\s/', $binary) === 1
            ? '"' . str_replace('"', '\\"', $binary) . '"'
            : escapeshellcmd($binary);

        $command = sprintf(
            '%s %s stdout -l %s --oem %d --psm %d tsv 2>&1',
            $binaryPart,
            escapeshellarg($imagePath),
            escapeshellarg($lang),
            $oem,
            $psm
        );

        $output = shell_exec($command);
        if (!is_string($output) || trim($output) === '') {
            throw new RuntimeException('Tesseract OCR returned empty output.');
        }

        if (!str_contains($output, "level\tpage_num\tblock_num")) {
            throw new RuntimeException('Tesseract OCR is not available or failed to run.');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];
        $lineBuckets = [];
        $acceptedConfidence = [];

        foreach ($lines as $index => $line) {
            if ($index === 0 || trim($line) === '') {
                continue;
            }

            $cols = explode("\t", $line);
            if (count($cols) < 12) {
                continue;
            }

            $text = trim($cols[11]);
            $conf = (float) $cols[10];
            if ($text === '' || $conf < $minConfidence) {
                continue;
            }

            $lineKey = $cols[2] . '_' . $cols[3] . '_' . $cols[4];
            $lineBuckets[$lineKey][] = $text;
            $acceptedConfidence[] = $conf;
        }

        $ocrLines = [];
        foreach ($lineBuckets as $bucket) {
            $txt = trim(implode(' ', $bucket));
            if ($txt !== '') {
                $ocrLines[] = $txt;
            }
        }

        $text = trim(implode("\n", $ocrLines));
        if ($text === '') {
            throw new RuntimeException('OCR text could not be recognized with sufficient confidence.');
        }

        $avgConfidence = count($acceptedConfidence) > 0
            ? (array_sum($acceptedConfidence) / count($acceptedConfidence))
            : 0.0;

        return [
            'engine' => 'tesseract-tsv',
            'text' => $text,
            'lines' => $ocrLines,
            'confidence' => $avgConfidence,
        ];
    }

    private function parseReceiptFields(string $ocrText, array $ocrLines, string $fallbackName): array
    {
        $merchant = trim((string) preg_replace('/[_\-]+/', ' ', pathinfo($fallbackName, PATHINFO_FILENAME)));
        $defaultDate = CarbonImmutable::now('UTC')->toIso8601String();
        $date = $defaultDate;
        $dateParsed = false;
        $items = [];
        $candidateTotal = 0.0;
        $merchantTemplate = 'generic';
        $firstTextLines = [];

        foreach ($ocrLines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if ($this->isProbablyMetadataLine($trimmed)) {
                continue;
            }

            $firstTextLines[] = $trimmed;
            if (count($firstTextLines) >= 4) {
                break;
            }
        }

        if (count($firstTextLines) > 0) {
            $merchant = $this->pickBestMerchantCandidate($firstTextLines, $merchant);
        }

        $templateParsed = $this->parseTemplateMerchantReceipt($merchant, $ocrLines);
        if ($templateParsed !== null) {
            $merchantTemplate = $templateParsed['template'];
            $items = $templateParsed['items'];
            $candidateTotal = $templateParsed['candidateTotal'];
            if ($templateParsed['date'] !== null) {
                $date = $templateParsed['date'];
                $dateParsed = true;
            }
        }

        foreach ($ocrLines as $line) {
            $normalized = strtolower(trim($line));

            if (preg_match('/(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})|(\d{4}[\/-]\d{1,2}[\/-]\d{1,2})/', $line, $dateMatch) === 1) {
                try {
                    $date = CarbonImmutable::parse($dateMatch[0])->toIso8601String();
                    $dateParsed = true;
                } catch (\Throwable) {
                    // Keep default date when OCR date token is invalid.
                }
            }

            $amounts = $this->extractAmountsFromLine($line);
            if (count($amounts) === 0) {
                continue;
            }

            if (preg_match('/\b(total|grand\s*total|jumlah|total\s*belanja|total\s*bayar|amount\s*due|tagihan)\b/i', $normalized) === 1) {
                $candidateTotal = max($candidateTotal, max($amounts));
                continue;
            }

            if (preg_match('/\b(subtotal|tax|ppn|kembalian|change|cash|debit|credit|diskon|discount|service|admin|biaya|pembulatan)\b/i', $normalized) === 1) {
                continue;
            }

            if (!$this->looksLikeItemLine($line)) {
                continue;
            }

            // When template parser already captured good items, keep generic parser as fallback only.
            if ($merchantTemplate !== 'generic' && count($items) >= 2) {
                continue;
            }

            $price = (float) max($amounts);
            $qty = 1;
            if (preg_match('/(\d{1,2})\s*[xX]/', $line, $qtyMatch) === 1) {
                $qty = max(1, (int) $qtyMatch[1]);
            }

            if (preg_match('/\b(\d{1,2})\s*[xX]\s*(?:rp\.?\s*)?([0-9\.,]{2,})\b/i', $line, $qtyPriceMatch) === 1) {
                $qty = max(1, (int) $qtyPriceMatch[1]);
                $parsedUnit = $this->normalizeCurrencyToken((string) $qtyPriceMatch[2]);
                if ($parsedUnit > 0) {
                    $price = $parsedUnit;
                }
            }

            $name = preg_replace('/(?:rp\.?\s*)?[0-9]{1,3}(?:[\.,][0-9]{3})*(?:[\.,][0-9]{1,2})?/i', '', $line);
            $name = preg_replace('/\b\d{1,2}\s*[xX]\b/', '', (string) $name);
            $name = trim(preg_replace('/\s+/', ' ', (string) $name));
            if ($name === '') {
                $name = 'Item';
            }

            $items[] = [
                'name' => $name,
                'qty' => $qty,
                'price' => round($price, 2),
                'confidence' => $this->scoreItemConfidence($line, $name, $qty, $price, $merchantTemplate !== 'generic'),
            ];
        }

        $itemsTotal = 0.0;
        foreach ($items as $item) {
            $itemsTotal += ((float) $item['qty']) * ((float) $item['price']);
        }

        $total = $candidateTotal > 0 ? $candidateTotal : $itemsTotal;
        if ($total <= 0) {
            $allAmounts = $this->extractAmountsFromLine($ocrText);
            $total = count($allAmounts) > 0 ? max($allAmounts) : 0;
        }

        if ($total > 0 && $itemsTotal <= 0) {
            $items = [['name' => 'Belanja', 'qty' => 1, 'price' => round($total, 2), 'confidence' => 0.45]];
            $itemsTotal = $total;
        }

        $inconsistency = abs($itemsTotal - $total);
        $allowedDiff = max(1500.0, $total * 0.03);
        if ($inconsistency > $allowedDiff && count($items) > 0) {
            foreach ($items as &$item) {
                $existing = (float) ($item['confidence'] ?? 0.6);
                $item['confidence'] = round(max(0.25, $existing - 0.12), 2);
            }
            unset($item);
        }

        $merchant = trim($merchant) !== '' ? trim($merchant) : 'Unknown Merchant';
        $merchant = mb_substr($merchant, 0, 80);

        $parserScore = 0.35;
        if ($merchant !== 'Unknown Merchant') {
            $parserScore += 0.2;
        }
        if ($total > 0) {
            $parserScore += 0.25;
        }
        if (count($items) > 0) {
            $parserScore += 0.2;
        }

        $fieldConfidence = [
            'merchant' => $merchant !== 'Unknown Merchant' ? 0.9 : 0.45,
            'date' => $dateParsed ? 0.8 : 0.55,
            'total' => $total > 0 ? ($candidateTotal > 0 ? 0.9 : 0.65) : 0.35,
            'items' => count($items) >= 3 ? 0.85 : (count($items) >= 1 ? 0.65 : 0.35),
        ];

        if ($merchantTemplate !== 'generic') {
            $fieldConfidence['items'] = min(0.95, $fieldConfidence['items'] + 0.1);
        }

        return [
            'merchant' => $merchant,
            'date' => $date,
            'total' => round($total, 2),
            'items' => $items,
            'parserScore' => min(1.0, $parserScore),
            'calculatedItemsTotal' => round($itemsTotal, 2),
            'fieldConfidence' => $fieldConfidence,
            'merchantTemplate' => $merchantTemplate,
        ];
    }

    private function parseTemplateMerchantReceipt(string $merchant, array $ocrLines): ?array
    {
        $normalizedMerchant = strtolower($merchant);
        $isTargetMerchant = str_contains($normalizedMerchant, 'alfamart')
            || str_contains($normalizedMerchant, 'indomaret')
            || str_contains($normalizedMerchant, 'minimarket');

        if (!$isTargetMerchant) {
            return null;
        }

        $items = [];
        $candidateTotal = 0.0;
        $detectedDate = null;

        foreach ($ocrLines as $line) {
            $clean = trim($line);
            if ($clean === '') {
                continue;
            }

            if ($detectedDate === null && preg_match('/(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})|(\d{4}[\/-]\d{1,2}[\/-]\d{1,2})/', $clean, $dm) === 1) {
                try {
                    $detectedDate = CarbonImmutable::parse($dm[0])->toIso8601String();
                } catch (\Throwable) {
                    $detectedDate = null;
                }
            }

            $lower = strtolower($clean);
            $amounts = $this->extractAmountsFromLine($clean);
            if (count($amounts) === 0) {
                continue;
            }

            if (preg_match('/\b(total|grand\s*total|jumlah|total\s*bayar)\b/i', $lower) === 1) {
                $candidateTotal = max($candidateTotal, max($amounts));
                continue;
            }

            if (!$this->looksLikeItemLine($clean)) {
                continue;
            }

            // Common minimarket pattern: ITEM NAME ... 12,500
            if (preg_match('/^(.*?)(?:\s{2,}|\t|\s-\s)(?:rp\.?\s*)?([0-9\.,]{3,})$/i', $clean, $m) === 1) {
                $name = trim((string) $m[1]);
                $price = $this->normalizeCurrencyToken((string) $m[2]);
                if ($name !== '' && $price > 0) {
                    $items[] = [
                        'name' => $name,
                        'qty' => 1,
                        'price' => round($price, 2),
                        'confidence' => $this->scoreItemConfidence($clean, $name, 1, $price, true),
                    ];
                    continue;
                }
            }

            // Pattern with quantity and unit price, e.g. 2x CHITATO 11.500
            if (preg_match('/^(\d{1,2})\s*[xX]\s*(.*?)\s+(?:rp\.?\s*)?([0-9\.,]{3,})$/i', $clean, $m2) === 1) {
                $qty = max(1, (int) $m2[1]);
                $name = trim((string) $m2[2]);
                $price = $this->normalizeCurrencyToken((string) $m2[3]);
                if ($name !== '' && $price > 0) {
                    $items[] = [
                        'name' => $name,
                        'qty' => $qty,
                        'price' => round($price, 2),
                        'confidence' => $this->scoreItemConfidence($clean, $name, $qty, $price, true),
                    ];
                }
            }
        }

        if (count($items) === 0 && $candidateTotal <= 0) {
            return null;
        }

        return [
            'template' => str_contains($normalizedMerchant, 'alfamart') ? 'alfamart' : (str_contains($normalizedMerchant, 'indomaret') ? 'indomaret' : 'minimarket'),
            'items' => $items,
            'candidateTotal' => $candidateTotal,
            'date' => $detectedDate,
        ];
    }

    private function isProbablyMetadataLine(string $line): bool
    {
        $normalized = strtolower(trim($line));
        if ($normalized === '') {
            return true;
        }

        return preg_match('/\b(total|subtotal|tax|ppn|cash|debit|credit|change|kembalian|telp|phone|alamat|address|npwp|invoice|struk|receipt|trx|transaksi|kasir)\b/i', $normalized) === 1;
    }

    private function pickBestMerchantCandidate(array $lines, string $fallback): string
    {
        $best = trim($fallback);
        $bestScore = 0;

        foreach ($lines as $line) {
            $candidate = trim($line);
            if ($candidate === '') {
                continue;
            }

            $score = 0;
            if (preg_match('/[a-z]/i', $candidate) === 1) {
                $score += 2;
            }
            if (preg_match('/^[A-Z0-9\s\.,&\-]{4,}$/', $candidate) === 1) {
                $score += 2;
            }
            if (preg_match('/\d{4,}/', $candidate) === 1) {
                $score -= 2;
            }
            if ($this->isProbablyMetadataLine($candidate)) {
                $score -= 3;
            }

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $bestScore > 0 ? $best : $fallback;
    }

    private function looksLikeItemLine(string $line): bool
    {
        if (preg_match('/[a-z]/i', $line) !== 1) {
            return false;
        }

        if (preg_match('/\b(total|subtotal|tax|ppn|kembalian|change|cash|debit|credit|diskon|discount|service|admin|biaya|telp|alamat|npwp|invoice|kasir|transaksi)\b/i', $line) === 1) {
            return false;
        }

        return preg_match('/(?:rp\.?\s*)?[0-9]{1,3}(?:[\.,][0-9]{3})*(?:[\.,][0-9]{1,2})?|[0-9]{4,}/i', $line) === 1;
    }

    private function scoreItemConfidence(string $line, string $name, int $qty, float $price, bool $fromTemplate): float
    {
        $score = $fromTemplate ? 0.78 : 0.58;

        if (preg_match('/(?:rp\.?\s*)?[0-9]{1,3}(?:[\.,][0-9]{3})*(?:[\.,][0-9]{1,2})?|[0-9]{4,}/i', $line) === 1) {
            $score += 0.08;
        }

        if (preg_match('/\b\d{1,2}\s*[xX]\b/', $line) === 1 || $qty > 1) {
            $score += 0.08;
        }

        if (mb_strlen($name) >= 4) {
            $score += 0.06;
        }

        if ($price >= 1000) {
            $score += 0.05;
        }

        if (stripos($name, 'item') !== false) {
            $score -= 0.14;
        }

        return round(max(0.25, min(0.98, $score)), 2);
    }

    /**
     * @return array<int,float>
     */
    private function extractAmountsFromLine(string $line): array
    {
        preg_match_all('/(?:rp\.?\s*)?([0-9]{1,3}(?:[\.,][0-9]{3})*(?:[\.,][0-9]{1,2})?|[0-9]{4,})/i', $line, $matches);
        $tokens = $matches[1] ?? [];
        $amounts = [];

        foreach ($tokens as $token) {
            $value = $this->normalizeCurrencyToken((string) $token);
            if ($value > 0) {
                $amounts[] = $value;
            }
        }

        return $amounts;
    }

    private function normalizeCurrencyToken(string $token): float
    {
        $value = trim(str_replace(' ', '', $token));
        if ($value === '') {
            return 0.0;
        }

        $commaCount = substr_count($value, ',');
        $dotCount = substr_count($value, '.');

        if ($commaCount > 0 && $dotCount > 0) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($commaCount > 0) {
            $lastCommaPos = strrpos($value, ',');
            $decimalLength = strlen($value) - $lastCommaPos - 1;
            if ($decimalLength <= 2) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } else {
            $lastDotPos = strrpos($value, '.');
            if ($lastDotPos !== false) {
                $decimalLength = strlen($value) - $lastDotPos - 1;
                if ($decimalLength > 2) {
                    $value = str_replace('.', '', $value);
                }
            }
        }

        return (float) $value;
    }

    private function buildAssistantReply(string $prompt, array $summary, string $message = ''): string
    {
        $income = number_format((float) ($summary['income'] ?? 0), 0, ',', '.');
        $expense = number_format((float) ($summary['expense'] ?? 0), 0, ',', '.');
        $net = number_format((float) ($summary['net'] ?? 0), 0, ',', '.');
        $topCategory = (string) ($summary['topExpenseCategory'] ?? 'Tidak ada');
        $topAmount = number_format((float) ($summary['topExpenseAmount'] ?? 0), 0, ',', '.');
        $warningCount = (int) ($summary['activeBudgetWarnings'] ?? 0);

        return match ($prompt) {
            'saving_tips' =>
                "1) Pakai limit harian untuk kategori {$topCategory}.\n"
                . "2) Tunda 24 jam untuk pembelian non-prioritas.\n"
                . "3) Amankan minimal 10% dari pemasukan ke tabungan otomatis.",
            'what_to_cut' =>
                "Kategori paling besar 30 hari terakhir: {$topCategory} (Rp{$topAmount}). Fokus pangkas 10-15% di kategori ini dulu agar dampaknya cepat terasa.",
            'budget_alerts' =>
                $warningCount > 0
                    ? "Ada {$warningCount} budget yang sudah di atas 80%. Prioritaskan review kategori tersebut hari ini."
                    : "Belum ada budget yang melewati 80%. Kondisi budget masih aman.",
            'free_text' =>
                "Pertanyaan kamu: \"{$message}\".\n"
                . "Berdasarkan data 30 hari: pemasukan Rp{$income}, pengeluaran Rp{$expense}, net Rp{$net}. "
                . "Fokus perbaikan tercepat ada di {$topCategory} (Rp{$topAmount}).",
            default =>
                "30 hari terakhir: pemasukan Rp{$income}, pengeluaran Rp{$expense}, saldo bersih Rp{$net}. "
                . "Pengeluaran terbesar ada di {$topCategory}.",
        };
    }

    public function chat(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');
        $userId = $user->idUser;

        $request->validate([
            'message' => 'required|string',
        ]);

        $message = $request->input('message');
        $service = new FinancialInsightService();

        $tools = [
            [
                'functionDeclarations' => [
                    [
                        'name' => 'getWalletBalances',
                        'description' => 'Gunakan tool ini untuk melihat daftar dompet/rekening pengguna beserta sisa saldonya saat ini.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => new \stdClass(),
                        ],
                    ],
                    [
                        'name' => 'getMonthlyAnalytics',
                        'description' => 'Gunakan tool ini untuk melihat ringkasan analitik pengeluaran dan pemasukan per kategori pada bulan tertentu.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'month' => [
                                    'type' => 'INTEGER',
                                    'description' => 'Bulan dalam format angka (1-12). Opsional.'
                                ],
                                'year' => [
                                    'type' => 'INTEGER',
                                    'description' => 'Tahun dalam format angka (misal: 2023). Opsional.'
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => 'getBudgetStatus',
                        'description' => 'Gunakan tool ini untuk melihat status limit budget pengguna dan mendeteksi apakah pengeluaran overbudget atau mendekati batas.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'month' => [
                                    'type' => 'INTEGER',
                                    'description' => 'Bulan dalam format angka (1-12). Opsional.'
                                ],
                                'year' => [
                                    'type' => 'INTEGER',
                                    'description' => 'Tahun dalam format angka. Opsional.'
                                ],
                            ],
                        ],
                    ],
                ]
            ]
        ];

        $systemInstruction = [
            'parts' => [
                ['text' => 'Kamu adalah Finansialin AI, asisten keuangan yang ramah. Jawab dengan gaya bahasa kasual (aku/kamu). Gunakan tools yang tersedia untuk mengambil data pengguna secara real-time. Jika pengguna tidak menyebutkan parameter waktu secara spesifik, asumsikan bulan dan tahun saat ini. Berikan insight yang proaktif berdasarkan saldo dompet dan pola pengeluarannya.']
            ]
        ];

        $payload = [
            'system_instruction' => $systemInstruction,
            'tools' => $tools,
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $message]
                    ]
                ]
            ]
        ];

        $apiKey = config('services.gemini.api_key');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $payload);

        $data = $response->json();

        // Cek apakah balasan merupakan Function Call
        if (isset($data['candidates'][0]['content']['parts'][0]['functionCall'])) {
            $functionCall = $data['candidates'][0]['content']['parts'][0]['functionCall'];
            $functionName = $functionCall['name'];
            $args = $functionCall['args'] ?? [];

            $functionResult = null;

            if ($functionName === 'getWalletBalances') {
                $functionResult = $service->getWalletBalances($userId);
            } elseif ($functionName === 'getMonthlyAnalytics') {
                $functionResult = $service->getMonthlyAnalytics($userId, $args['month'] ?? null, $args['year'] ?? null);
            } elseif ($functionName === 'getBudgetStatus') {
                $functionResult = $service->getBudgetStatus($userId, $args['month'] ?? null, $args['year'] ?? null);
            }

            // Membangun payload putaran kedua untuk meneruskan Hasil Fungsi ke Gemini
            $payload['contents'][] = $data['candidates'][0]['content']; // history dari model (berisi call function)
            $payload['contents'][] = [
                'role' => 'user', // Wait! actually gemini needs `role: 'user'` for functionResponse or `role: 'function'`? No, role: 'user' inside Gemini Flash or `role: 'function'`. Actually Gemini Docs prefer role 'function'/part 'functionResponse' but sometimes 'user' with 'functionResponse'.
                'parts' => [
                    [
                        'functionResponse' => [
                            'name' => $functionName,
                            'response' => [
                                'name' => $functionName,
                                'content' => $functionResult
                            ]
                        ]
                    ]
                ]
            ];

            // Re-request ke Gemini dengan history dan data balasan
            $secondResponse = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);

            $data = $secondResponse->json();
        }

        $finalAnswer = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Maaf, aku tidak bisa memproses permintaan saat ini.';

        return response()->json([
            'reply' => $finalAnswer
        ]);
    }
}
