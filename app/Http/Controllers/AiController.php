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
use Illuminate\Support\Facades\Log;
use Throwable;

class AiController extends Controller
{
    protected $insightService;

    public function __construct()
    {
        $this->insightService = new FinancialInsightService();
    }

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
                ->whereBetween('date', [$budget->periodStart->startOfDay(), $budget->periodEnd->endOfDay()])
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
        set_time_limit(120);

        $validator = Validator::make($request->all(), [
            'receiptImage' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('receiptImage');
        if ($file === null) {
            return response()->json(['message' => 'receiptImage is required'], 422);
        }

        // ================= GEMINI MULTIMODAL OCR (Fast & Highly Accurate) =================
        $apiKey = trim((string) config('services.gemini.api_key', ''));
        Log::info("receiptOcr: Checking Gemini API Key. Key configured: " . ($apiKey !== '' ? 'Yes' : 'No'));
        
        if ($apiKey !== '') {
            $verify = true;
            $caBundle = trim((string) config('services.gemini.ca_bundle', ''));
            if ($caBundle !== '') {
                $verify = $caBundle;
            } else {
                $rawVerify = config('services.gemini.ssl_verify', true);
                if (is_string($rawVerify)) {
                    $verify = !in_array(strtolower(trim($rawVerify)), ['0', 'false', 'off', 'no'], true);
                } else {
                    $verify = (bool) $rawVerify;
                }
            }

            try {
                $modelsToTry = [
                    'gemini-2.5-flash',
                    'gemini-2.0-flash', 
                    'gemini-1.5-flash',
                ];

                $response = null;
                $geminiData = null;

                foreach ($modelsToTry as $model) {
                    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
                    
                    $mimeType = $file->getMimeType();
                    $base64Data = base64_encode(file_get_contents($file->getRealPath()));

                    Log::info("receiptOcr: Attempting Gemini model {$model}");

                    $payload = [
                        'contents' => [
                            [
                                'parts' => [
                                    [
                                        'text' => 'Extract receipt data. Return JSON only with keys: merchant_name (string or null), total_amount (integer number in Indonesian Rupiah, no thousand separators, no decimals), date (YYYY-MM-DD or null), suggested_category (number, use default 10). Treat Indonesian separators as thousands: 43.000 must be 43000, 93.200 must be 93200. Do not return 43 for 43.000. Do not include any extra markdown formatting or text outside of the JSON.'
                                    ],
                                    [
                                        'inlineData' => [
                                            'mimeType' => $mimeType,
                                            'data' => $base64Data
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json'
                        ]
                    ];

                    $res = Http::withHeaders([
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->withOptions(['verify' => $verify])
                    ->timeout(20)
                    ->post($url, $payload);

                    Log::info("receiptOcr: Gemini {$model} response status: " . $res->status());

                    if ($res->successful()) {
                        $response = $res;
                        $geminiData = $res->json();
                        break;
                    } else {
                        Log::warning("receiptOcr: Gemini {$model} response failed: " . json_encode($res->json()));
                    }
                }

                if ($response && isset($geminiData['candidates'][0]['content']['parts'][0]['text'])) {
                    $textResult = trim($geminiData['candidates'][0]['content']['parts'][0]['text']);
                    Log::info("receiptOcr: Gemini raw text result: " . $textResult);
                    
                    // Clean code blocks if present
                    if (strpos($textResult, '```') === 0) {
                        $textResult = preg_replace('/^```(?:json)?\s*/i', '', $textResult);
                        $textResult = preg_replace('/\s*```$/i', '', $textResult);
                        $textResult = trim($textResult);
                    }

                    $extracted = json_decode($textResult, true);
                    if (is_array($extracted)) {
                        Log::info("receiptOcr: Gemini extraction success", $extracted);
                        return response()->json([
                            'status' => 'success',
                            'data' => [
                                'merchant_name' => $extracted['merchant_name'] ?? null,
                                'total_amount' => $this->normalizeReceiptAmount($extracted['total_amount'] ?? null, $textResult),
                                'date' => $extracted['date'] ?? null,
                                'suggested_category' => $extracted['suggested_category'] ?? 10
                            ],
                            'engine' => 'gemini'
                        ], 200);
                    } else {
                        Log::warning("receiptOcr: Gemini text could not be parsed as JSON: " . $textResult);
                    }
                } else {
                    Log::warning("receiptOcr: Gemini response missing content or invalid. Response: " . json_encode($geminiData));
                }
            } catch (\Exception $geminiEx) {
                Log::warning("Gemini OCR failed, falling back to local OCR: " . $geminiEx->getMessage());
            }
        }

        // ================= FALLBACK: LOCAL FASTAPI OCR QUEUE (Donut Model) =================
        $aiServiceUrl = rtrim((string) config('services.ocr.service_url', 'http://127.0.0.1:8002'), '/');
        Log::info("receiptOcr: Falling back to local OCR at: {$aiServiceUrl}");

        try {
            $queuedResult = $this->runQueuedOcr($file, $aiServiceUrl);

            if ($queuedResult['ok']) {
                Log::info("receiptOcr: Local OCR success. Response: " . json_encode($queuedResult['body']));
                return response()->json($queuedResult['body'], 200);
            }

            Log::error("receiptOcr: Local OCR failed. Response: " . json_encode($queuedResult));
            return response()->json([
                'message' => $queuedResult['message'],
                'details' => $queuedResult['body'],
            ], $queuedResult['status']);

        } catch (\Exception $e) {
            Log::error("receiptOcr: Local OCR connection exception: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to connect to AI service. Pastikan Python AI service berjalan di port yang benar (OCR_AI_SERVICE_URL di .env).',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function runQueuedOcr(UploadedFile $file, string $aiServiceUrl): array
    {
        $submitResponse = Http::timeout(30)->attach(
            'receiptImage',
            file_get_contents($file->getRealPath()),
            $file->getClientOriginalName()
        )->post("{$aiServiceUrl}/ocr/jobs");

        if (!$submitResponse->successful()) {
            return [
                'ok' => false,
                'status' => $submitResponse->status(),
                'message' => 'AI Service Error',
                'body' => $submitResponse->json(),
            ];
        }

        $submitPayload = $submitResponse->json();
        $jobId = is_array($submitPayload) ? ($submitPayload['job_id'] ?? null) : null;

        if (!is_string($jobId) || $jobId === '') {
            return [
                'ok' => false,
                'status' => 502,
                'message' => 'AI Service returned an invalid OCR job response',
                'body' => $submitPayload,
            ];
        }

        $deadline = microtime(true) + 110;
        $lastPayload = $submitPayload;

        do {
            usleep(750000);

            $pollResponse = Http::timeout(10)->get("{$aiServiceUrl}/ocr/jobs/" . rawurlencode($jobId));
            $lastPayload = $pollResponse->json();

            if (!$pollResponse->successful()) {
                return [
                    'ok' => false,
                    'status' => $pollResponse->status(),
                    'message' => 'AI Service Error',
                    'body' => $lastPayload,
                ];
            }

            if (!is_array($lastPayload)) {
                continue;
            }

            $status = (string) ($lastPayload['status'] ?? 'unknown');

            if ($status === 'done') {
                $result = $lastPayload['result'] ?? [];
                return [
                    'ok' => true,
                    'status' => 200,
                    'message' => 'OCR completed',
                    'body' => $this->normalizeQueuedOcrResult(is_array($result) ? $result : []),
                ];
            }

            if ($status === 'failed') {
                return [
                    'ok' => false,
                    'status' => 502,
                    'message' => 'AI Service OCR job failed',
                    'body' => $lastPayload,
                ];
            }
        } while (microtime(true) < $deadline);

        return [
            'ok' => false,
            'status' => 504,
            'message' => 'AI Service OCR job timed out',
            'body' => $lastPayload,
        ];
    }

    private function normalizeQueuedOcrResult(array $result): array
    {
        $data = $result['data'] ?? $result;
        $data = is_array($data) ? $data : [];

        $response = [
            'status' => $result['status'] ?? 'success',
            'data' => [
                'merchant_name' => $data['merchant_name'] ?? null,
                'total_amount' => $this->normalizeReceiptAmount($data['total_amount'] ?? null),
                'date' => $data['date'] ?? null,
                'suggested_category' => $data['suggested_category'] ?? 10,
            ],
            'engine' => 'donut',
        ];

        if (array_key_exists('debug_raw_ai', $result)) {
            $response['debug_raw_ai'] = $result['debug_raw_ai'];
        }

        return $response;
    }

    private function normalizeReceiptAmount(mixed $amount, ?string $rawPayload = null): float
    {
        if ($rawPayload !== null && preg_match('/"total_amount"\s*:\s*"?([^",}\s]+)"?/i', $rawPayload, $match)) {
            $fromRawPayload = $this->parseReceiptAmountString($match[1]);
            if ($fromRawPayload > 0) {
                return $fromRawPayload;
            }
        }

        if (is_string($amount)) {
            return $this->parseReceiptAmountString($amount);
        }

        if (is_int($amount) || is_float($amount)) {
            $value = (float) $amount;

            if ($value > 0 && $value < 1000) {
                $raw = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
                $digits = preg_replace('/\D/', '', $raw) ?? '';

                if (strlen($digits) <= 2) {
                    return (float) ((int) $digits * 1000);
                }

                if (strlen($digits) === 3) {
                    return (float) ((int) $digits * 100);
                }
            }

            return $value;
        }

        return 0.0;
    }

    private function parseReceiptAmountString(string $rawAmount): float
    {
        $amountPart = preg_replace('/[^\d\.,]/', '', $rawAmount) ?? '';

        if ($amountPart === '') {
            return 0.0;
        }

        $lastDot = strrpos($amountPart, '.');
        $lastComma = strrpos($amountPart, ',');
        $lastSeparator = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);

        if ($lastSeparator >= 0) {
            $before = substr($amountPart, 0, $lastSeparator);
            $after = substr($amountPart, $lastSeparator + 1);
            $beforeDigits = preg_replace('/\D/', '', $before) ?? '';
            $hasOtherSeparator = str_contains($before, '.') || str_contains($before, ',');

            if (strlen($after) === 2 && ($hasOtherSeparator || strlen($beforeDigits) > 3)) {
                $amountPart = $before;
            }
        }

        $clean = preg_replace('/\D/', '', $amountPart) ?? '';

        if ($clean === '') {
            return 0.0;
        }

        return (float) $clean;
    }

    public function dashboardSummary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');
        
        $now = CarbonImmutable::now('UTC');
        
        // Calculate total income and expense
        $totalIncome = (float) Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('type', 'income')
            ->sum('amount');
        
        $totalExpense = (float) Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('type', 'expense')
            ->sum('amount');
        
        $totalBalance = $totalIncome - $totalExpense;
        
        // Get income grouped by last 6 months
        $last6Months = [];
        $incomeChartData = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthDate = $now->subMonths($i)->startOfMonth();
            $monthKey = $monthDate->format('Y-m');
            $last6Months[] = $monthKey;
            
            $monthIncome = (float) Transaction::query()
                ->where('idUser', $user->idUser)
                ->where('type', 'income')
                ->whereBetween('date', [$monthDate, $monthDate->endOfMonth()])
                ->sum('amount');
            
            $incomeChartData[] = [
                'month' => $monthDate->format('M'),
                'amount' => round($monthIncome, 2),
            ];
        }
        
        // Get 3 most recent transactions with category names
        $recentTransactions = Transaction::query()
            ->where('idUser', $user->idUser)
            ->with('category:idCategory,name')
            ->orderByDesc('date')
            ->limit(3)
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->idTransaction,
                    'description' => $transaction->description,
                    'type' => $transaction->type,
                    'amount' => round($transaction->amount, 2),
                    'date' => $transaction->date->format('Y-m-d'),
                    'categoryName' => $transaction->category?->name ?? 'Uncategorized',
                ];
            });
        
        // Calculate metrics for AI summary
        $recentTx = Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('date', '>=', $now->subDays(30))
            ->get();
        
        $income30 = (float) $recentTx->where('type', 'income')->sum('amount');
        $expense30 = (float) $recentTx->where('type', 'expense')->sum('amount');
        $net30 = $income30 - $expense30;
        
        $categories = Category::query()->pluck('name', 'idCategory');
        $expenseByCategory = [];
        foreach ($recentTx->where('type', 'expense') as $tx) {
            $catName = $categories[$tx->idCategory] ?? 'Uncategorized';
            $expenseByCategory[$catName] = ($expenseByCategory[$catName] ?? 0) + (float) $tx->amount;
        }
        arsort($expenseByCategory);
        $topCategory = array_key_first($expenseByCategory);
        $topCategoryAmount = $topCategory ? (float) $expenseByCategory[$topCategory] : 0.0;

        $summaryData = [
            'income' => $income30,
            'expense' => $expense30,
            'net' => $net30,
            'topExpenseCategory' => $topCategory,
            'topExpenseAmount' => $topCategoryAmount,
        ];

        $aiSummaryText = $this->buildAssistantReply('summary', $summaryData);

        return response()->json([
            'summary' => $aiSummaryText,
            'totalIncome' => round($totalIncome, 2),
            'totalExpense' => round($totalExpense, 2),
            'totalBalance' => round($totalBalance, 2),
            'incomeChartData' => $incomeChartData,
            'recentTransactions' => $recentTransactions,
        ]);
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
            'history' => 'nullable|array',
        ]);

        $message = $request->input('message');
        $history = $request->input('history', []);
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
                        'name' => 'getMonthlySummary',
                        'description' => 'Gunakan tool ini untuk mendapatkan total income, total expense, net, dan kategori pengeluaran terbesar pada bulan tertentu.',
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
                        'name' => 'getAllTimeSummary',
                        'description' => 'Gunakan tool ini untuk mendapatkan total income dan expense sepanjang waktu (seluruh data).',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => new \stdClass(),
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
                    [
                        'name' => 'getRecentTransactions',
                        'description' => 'Gunakan tool ini untuk melihat riwayat pengeluaran atau pemasukan terakhir pengguna.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'limit' => [
                                    'type' => 'INTEGER',
                                    'description' => 'Jumlah transaksi yang ingin diambil. Standarnya adalah 5.'
                                ]
                            ],
                        ],
                    ],
                    [
                        'name' => 'getSpendingTrend',
                        'description' => 'Gunakan tool ini untuk melihat tren income/expense beberapa bulan terakhir.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'months' => [
                                    'type' => 'INTEGER',
                                    'description' => 'Jumlah bulan terakhir yang ingin dianalisis (1-12). Opsional.'
                                ]
                            ],
                        ],
                    ],
                    [
                        'name' => 'getUserFinancialProfile',
                        'description' => 'Gunakan tool ini untuk melihat ringkasan profil finansial (saldo total, total income/expense, net).',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => new \stdClass(),
                        ],
                    ],
                    [
                        'name' => 'getSavingsGoals',
                        'description' => 'Gunakan tool ini untuk melihat target/budget aktif pengguna sebagai referensi goals.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => new \stdClass(),
                        ],
                    ],
                ]
            ]
        ];

        $systemInstruction = [
            'parts' => [
                ['text' => <<<EOD
Kamu adalah Finansialin AI, asisten keuangan pribadi yang sangat cerdas, analitis, proaktif, dan empatik. 
Nama pengguna yang sedang kamu bantu adalah: {$user->name}. 

TUGAS UTAMAMU:
1. Memberikan analisis keuangan yang sangat MENDALAM, DESKRIPTIF, dan TERSTRUKTUR.
2. Gunakan tools secara agresif untuk mendapatkan data riil sebelum memberikan saran.
3. Berikan saran strategis dan tips praktis: Selalu berikan tips/langkah konkret untuk menghemat uang, mengoptimalkan budget, atau mengelola keuangan di setiap jawaban Anda.
4. Selalu sapa pengguna dengan nama mereka: {$user->name}.

GAYA KOMUNIKASI (SANGAT PENTING):
- JANGAN PERNAH memberikan jawaban singkat satu atau dua paragraf saja. Jawaban Anda harus panjang, deskriptif, detail, dan berisi tips/strategi finansial yang bermanfaat.
- Gunakan format Markdown yang kaya (Bold, Italic, Tables, Lists) untuk membuat jawaban mudah dibaca.
- Jika pertanyaan pengguna berkaitan dengan data keuangan (seperti tren pengeluaran, pemasukan, perbandingan kategori belanja, saldo dompet, anggaran/budget, atau analisis data lainnya), Anda WAJIB menyertakan visualisasi grafik di akhir jawaban Anda. Jangan menunggu pengguna meminta grafik secara eksplisit; buatlah grafik secara otomatis jika datanya cocok divisualisasikan.
- Jika Anda menghasilkan grafik, gunakan format berikut di baris paling akhir jawaban Anda:
  [CHART_DATA: {"type": "line", "labels": ["Jan", "Feb", "Mar"], "values": [100000, 200000, 150000], "title": "Tren Pengeluaran"}]
  (Gunakan type: 'line' untuk tren perkembangan, 'bar' untuk perbandingan nominal/saldo/kategori, dan 'pie' untuk distribusi pengeluaran/pemasukan).
- Pastikan format JSON di dalam [CHART_DATA: ...] valid dan berisi labels serta values berupa angka riil hasil query tools Anda.

KONTEKS MEMORI:
- Kamu menerima riwayat percakapan. Ingatlah preferensi dan pertanyaan sebelumnya.
- Jika pengguna menyebutkan tujuan keuangan, catat itu dalam analisis ke depan.
EOD
                ]
            ]
        ];

        Log::info('AI Chat Request', [
            'user_id' => $user->idUser,
            'user_name' => $user->name,
            'message' => $message,
            'history_count' => count($history)
        ]);

        $contents = [];
        foreach ($history as $chatItem) {
            if (isset($chatItem['role']) && isset($chatItem['text'])) {
                $contents[] = [
                    'role' => $chatItem['role'],
                    'parts' => [
                        ['text' => $chatItem['text']]
                    ]
                ];
            }
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $message]
            ]
        ];

        $payload = [
            'system_instruction' => $systemInstruction,
            'tools'              => $tools,
            'tool_config'        => [
                'function_calling_config' => ['mode' => 'AUTO'],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 4096,
            ],
        ];

        $apiKey = trim((string) config('services.gemini.api_key', ''));
        if ($apiKey === '') {
            return $this->localFallbackChat($userId, $message, $user);
        }

        $verify = true;
        $caBundle = trim((string) config('services.gemini.ca_bundle', ''));
        if ($caBundle !== '') {
            $verify = $caBundle;
        } else {
            $rawVerify = config('services.gemini.ssl_verify', true);
            if (is_string($rawVerify)) {
                $verify = !in_array(strtolower(trim($rawVerify)), ['0', 'false', 'off', 'no'], true);
            } else {
                $verify = (bool) $rawVerify;
            }
        }

        try {
            // Models to try in order of preference
            $modelsToTry = [
                'gemini-2.5-flash',
                'gemini-2.0-flash', 
                'gemini-1.5-flash',
                'gemini-1.5-flash-8b',
                'gemini-1.5-pro',
                'gemini-pro',
            ];

            $lastError = null;
            $attempted = [];
            $maxAttempts = 8; 
            $response = null;
            $data = null;

            for ($i = 0; $i < $maxAttempts; $i++) {
                $model = $modelsToTry[$i] ?? null;
                if (!$model) break;
                
                if (in_array($model, $attempted)) continue;
                $attempted[] = $model;

                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
                Log::info("Calling Gemini API", ['attempt' => $i + 1, 'model' => $model]);

                try {
                    $response = Http::withHeaders([
                        'Content-Type'   => 'application/json',
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->withOptions(['verify' => $verify])
                    ->timeout(15)
                    ->post($url, $payload);

                    $data = $response->json();

                    if ($response->successful()) {
                        Log::info("Gemini Success", ['model' => $model]);
                        break; // Exit the loop on success
                    }

                    $status = $response->status();
                    Log::warning("Gemini API Error", ['status' => $status, 'model' => $model, 'error' => $data]);

                    // If 404, list available models and add them to the end of the queue
                    if ($status === 404 && count($modelsToTry) < 15) {
                        $availableRes = Http::withHeaders(['x-goog-api-key' => $apiKey])
                            ->withOptions(['verify' => $verify])
                            ->get("https://generativelanguage.googleapis.com/v1beta/models");
                        
                        if ($availableRes->successful()) {
                            $availableData = $availableRes->json();
                            foreach (($availableData['models'] ?? []) as $m) {
                                $cleanName = str_replace('models/', '', $m['name']);
                                if (!in_array($cleanName, $modelsToTry) && (str_contains($cleanName, 'flash') || str_contains($cleanName, 'pro'))) {
                                    $modelsToTry[] = $cleanName;
                                }
                            }
                        }
                    }

                    $lastError = $data['error']['message'] ?? 'Unknown error';
                    
                    if (in_array($status, [429, 500, 503], true)) {
                        sleep(1);
                    }

                } catch (\Exception $e) {
                    Log::error("Gemini Loop Exception", ['message' => $e->getMessage()]);
                    $lastError = $e->getMessage();
                }
            }

            if (!$response || $response->failed()) {
                Log::warning("Gemini API completely failed. Falling back to local responder.", ['last_error' => $lastError]);
                return $this->localFallbackChat($userId, $message, $user);
            }
        } catch (Throwable $e) {
            Log::warning("Gemini API throw exception. Falling back to local responder.", ['error' => $e->getMessage()]);
            return $this->localFallbackChat($userId, $message, $user);
        }

        if (!isset($data['candidates'][0]['content']['parts'])) {
            Log::error('Gemini API response missing parts. Falling back.', [
                'details' => $data,
            ]);
            return $this->localFallbackChat($userId, $message, $user);
        }

        // ── Scan ALL parts for a functionCall ────────────────────────────────
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $functionCallPart = null;
        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $functionCallPart = $part['functionCall'];
                break;
            }
        }

        if ($functionCallPart !== null) {
            $functionName = $functionCallPart['name'];
            $args         = $functionCallPart['args'] ?? [];

            $functionResult = match ($functionName) {
                'getWalletBalances'     => $service->getWalletBalances($userId),
                'getMonthlyAnalytics'   => $service->getMonthlyAnalytics($userId, $args['month'] ?? null, $args['year'] ?? null),
                'getMonthlySummary'     => $service->getMonthlySummary($userId, $args['month'] ?? null, $args['year'] ?? null),
                'getAllTimeSummary'     => $service->getAllTimeSummary($userId),
                'getBudgetStatus'       => $service->getBudgetStatus($userId, $args['month'] ?? null, $args['year'] ?? null),
                'getRecentTransactions' => $service->getRecentTransactions($userId, $args['limit'] ?? 5),
                'getSpendingTrend'      => $service->getSpendingTrend($userId, $args['months'] ?? 3),
                'getUserFinancialProfile' => $service->getUserFinancialProfile($userId),
                'getSavingsGoals'       => $service->getSavingsGoals($userId),
                default                 => [],
            };

            $modelContent = $data['candidates'][0]['content'];
            foreach ($modelContent['parts'] as &$p) {
                if (isset($p['functionCall']['args']) && is_array($p['functionCall']['args']) && count($p['functionCall']['args']) === 0) {
                    $p['functionCall']['args'] = new \stdClass();
                }
            }
            unset($p);

            $payload['contents'][] = $modelContent;
            $payload['contents'][] = [
                'role'  => 'tool', 
                'parts' => [
                    [
                        'functionResponse' => [
                            'name'     => $functionName,
                            'response' => ['content' => $functionResult],
                        ]
                    ]
                ],
            ];

            // Second Gemini request
            try {
                $secondResponse = null;
                $data = null;
                foreach ($modelsToTry as $model) {
                    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
                    Log::info("Calling Gemini API (Round 2)", ['model' => $model]);
                    
                    $secondResponse = Http::withHeaders([
                        'Content-Type'   => 'application/json',
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->withOptions(['verify' => $verify])
                    ->timeout(15)
                    ->post($url, $payload);
                    
                    $data = $secondResponse->json();

                    if ($secondResponse->successful()) {
                        break;
                    }

                    $status = $secondResponse->status();
                    Log::warning('Gemini API Error (2nd request)', [
                        'status'  => $status,
                        'model'   => $model,
                        'details' => $data,
                    ]);

                    if (in_array($status, [429, 503, 504], true)) {
                        sleep(1);
                        continue;
                    }

                    if ($status === 404) {
                        continue;
                    }

                    break;
                }

                if (!$secondResponse || $secondResponse->failed()) {
                    Log::warning("Gemini 2nd request failed. Falling back.");
                    return $this->localFallbackChat($userId, $message, $user);
                }
            } catch (Throwable $e) {
                Log::warning("Gemini 2nd request exception. Falling back.", ['error' => $e->getMessage()]);
                return $this->localFallbackChat($userId, $message, $user);
            }
        }

        if (!isset($data['candidates'][0]['content']['parts'])) {
            Log::error('Gemini API response missing final parts. Falling back.', [
                'details' => $data,
            ]);
            return $this->localFallbackChat($userId, $message, $user);
        }

        $reply = 'Maaf, aku tidak bisa memproses permintaan saat ini.';
        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (isset($part['text']) && trim($part['text']) !== '') {
                $reply = $part['text'];
                break;
            }
        }

        return response()->json([
            'reply' => $reply
        ]);
    }

    private function localFallbackChat(int $userId, string $message, User $user): JsonResponse
    {
        $msgLower = strtolower($message);
        $service = $this->insightService;

        // 1. Wallets / Saldo
        if (str_contains($msgLower, 'saldo') || str_contains($msgLower, 'wallet') || str_contains($msgLower, 'dompet') || str_contains($msgLower, 'rekening') || str_contains($msgLower, 'uang')) {
            $wallets = $service->getWalletBalances($userId);
            if ($wallets->isEmpty()) {
                $reply = "Halo {$user->name}! Saat ini kamu belum memiliki dompet aktif. Silakan tambahkan dompet terlebih dahulu di dashboard.";
            } else {
                $total = 0;
                $listStr = "";
                $labels = [];
                $values = [];
                foreach ($wallets as $w) {
                    $total += $w['balance'];
                    $listStr .= "- **" . $w['wallet_name'] . "**: Rp " . number_format($w['balance'], 0, ',', '.') . "\n";
                    $labels[] = $w['wallet_name'];
                    $values[] = (float)$w['balance'];
                }
                
                $chartData = [
                    'type' => 'bar',
                    'labels' => $labels,
                    'values' => $values,
                    'title' => 'Daftar Saldo Dompet Anda'
                ];

                $reply = "Halo {$user->name}!\n\n"
                    . "Berikut adalah rincian saldo dompet dan rekening kamu saat ini. Menyimpan dana di beberapa tempat adalah langkah baik untuk membagi pos keuangan Anda:\n\n" 
                    . $listStr 
                    . "\n💰 **Total Saldo Keseluruhan**: **Rp " . number_format($total, 0, ',', '.') . "**\n\n"
                    . "**Tips Finansial untuk Anda:**\n"
                    . "1. **Pisahkan Rekening Utama & Tabungan**: Hindari mencampur uang belanja harian dengan tabungan darurat agar saldo tidak terpakai secara tidak sengaja.\n"
                    . "2. **Pantau Selisih Bunga/Biaya Admin**: Pastikan biaya admin bulanan rekening Anda tidak menggerus saldo secara berlebihan.\n"
                    . "3. **Alokasikan Uang Dingin**: Jika total saldo Anda mencukupi, pertimbangkan untuk memindahkan sebagian dana ke instrumen investasi rendah risiko (seperti reksa dana pasar uang) agar nilainya bertumbuh.\n\n"
                    . "Berikut adalah grafik visualisasi saldo dompet Anda saat ini:\n\n"
                    . "[CHART_DATA: " . json_encode($chartData) . "]";
            }
            return response()->json(['reply' => $reply]);
        }

        // 2. Transactions / Riwayat / History
        if (str_contains($msgLower, 'transaksi') || str_contains($msgLower, 'riwayat') || str_contains($msgLower, 'history') || str_contains($msgLower, 'pengeluaran') || str_contains($msgLower, 'pemasukan')) {
            $txs = $service->getRecentTransactions($userId, 5);
            if ($txs->isEmpty()) {
                $reply = "Halo {$user->name}! Kamu belum memiliki riwayat transaksi keuangan apa pun.";
            } else {
                $listStr = "| Tanggal | Keterangan | Kategori | Jenis | Jumlah | Dompet |\n";
                $listStr .= "| --- | --- | --- | --- | --- | --- |\n";
                $labels = [];
                $values = [];
                foreach ($txs as $t) {
                    $typeStr = $t['type'] === 'income' ? '📈 Pemasukan' : '📉 Pengeluaran';
                    $dateOnly = substr($t['date'], 0, 10);
                    $listStr .= "| {$dateOnly} | {$t['description']} | {$t['category']} | {$typeStr} | Rp " . number_format($t['amount'], 0, ',', '.') . " | {$t['source']} |\n";
                    
                    $labels[] = strlen($t['description']) > 15 ? substr($t['description'], 0, 12) . '...' : $t['description'];
                    $values[] = (float)$t['amount'];
                }
                
                $chartData = [
                    'type' => 'bar',
                    'labels' => $labels,
                    'values' => $values,
                    'title' => 'Nominal Transaksi Terakhir'
                ];

                $reply = "Halo {$user->name}!\n\n"
                    . "Berikut adalah rincian **5 transaksi terakhir** yang telah Anda catat di sistem Finansialin:\n\n" 
                    . $listStr 
                    . "\n"
                    . "**Tips Evaluasi Transaksi:**\n"
                    . "1. **Catat Secara Real-Time**: Biasakan langsung mencatat pengeluaran begitu transaksi terjadi agar tidak ada pengeluaran 'gaib' yang lupa dicatat.\n"
                    . "2. **Evaluasi Pengeluaran Kecil (Latte Factor)**: Perhatikan pengeluaran kecil yang berulang (seperti kopi harian, biaya parkir, atau biaya transfer). Tanpa disadari, akumulasinya bisa sangat besar.\n"
                    . "3. **Bandingkan Pemasukan vs Pengeluaran**: Pastikan arus kas bulanan Anda tetap positif (pemasukan lebih besar daripada pengeluaran) demi menjaga stabilitas keuangan jangka panjang.\n\n"
                    . "Berikut adalah grafik visualisasi nominal dari transaksi terbaru Anda:\n\n"
                    . "[CHART_DATA: " . json_encode($chartData) . "]";
            }
            return response()->json(['reply' => $reply]);
        }

        // 3. Budget Status
        if (str_contains($msgLower, 'budget') || str_contains($msgLower, 'anggaran') || str_contains($msgLower, 'limit')) {
            $budgets = $service->getBudgetStatus($userId);
            if ($budgets->isEmpty()) {
                $reply = "Halo {$user->name}! Kamu belum mengatur limit budget/anggaran belanja kategori apa pun untuk bulan ini. Menetapkan budget sangat disarankan untuk menjaga kesehatan keuanganmu!";
            } else {
                $listStr = "| Kategori | Batas Limit | Terpakai | Sisa | Status |\n";
                $listStr .= "| --- | --- | --- | --- | --- |\n";
                $labels = [];
                $values = [];
                foreach ($budgets as $b) {
                    $statusSymbol = $b->status === 'Overbudget' ? '🚨 Overbudget' : ($b->status === 'Warning' ? '⚠️ Warning' : '✅ Aman');
                    $listStr .= "| {$b->category_name} | Rp " . number_format($b->budget_limit, 0, ',', '.') 
                        . " | Rp " . number_format($b->total_spent, 0, ',', '.') 
                        . " | Rp " . number_format($b->remaining_budget, 0, ',', '.') 
                        . " | {$statusSymbol} |\n";
                    
                    $labels[] = $b->category_name;
                    $values[] = (float)$b->total_spent;
                }
                
                $chartData = [
                    'type' => 'bar',
                    'labels' => $labels,
                    'values' => $values,
                    'title' => 'Pengeluaran per Budget Kategori'
                ];

                $reply = "Halo {$user->name}!\n\n"
                    . "Berikut adalah status **anggaran belanja (budget)** kamu untuk bulan ini. Memantau budget membantu Anda mendeteksi kebocoran dana lebih awal:\n\n" 
                    . $listStr 
                    . "\n"
                    . "**Tips Mengelola Budget Belanja:**\n"
                    . "1. **Prioritaskan Kategori Rawan**: Fokuslah mengontrol ketat kategori yang saat ini berstatus **Warning** (mendekati limit) atau **Overbudget**.\n"
                    . "2. **Gunakan Metode Amplop**: Jika sulit menahan diri, bagi budget bulanan menjadi alokasi mingguan agar Anda tidak kehabisan uang di tengah bulan.\n"
                    . "3. **Sesuaikan Limit Bulan Depan**: Jika suatu kategori terus-menerus overbudget, mungkin batas limitnya terlalu rendah. Sesuaikan dengan realita kebutuhan Anda secara bijak.\n\n"
                    . "Berikut adalah grafik pengeluaran dari masing-masing kategori budget Anda:\n\n"
                    . "[CHART_DATA: " . json_encode($chartData) . "]";
            }
            return response()->json(['reply' => $reply]);
        }

        // 4. Analytics / Kategori Terbesar / Analisis
        if (str_contains($msgLower, 'analitik') || str_contains($msgLower, 'kategori') || str_contains($msgLower, 'terbesar') || str_contains($msgLower, 'analisis') || str_contains($msgLower, 'grafik')) {
            $summary = $service->getMonthlySummary($userId);
            if (empty($summary['expenseByCategory'])) {
                $reply = "Halo {$user->name}! Belum ada pengeluaran yang tercatat pada bulan ini untuk dianalisis.";
            } else {
                $listStr = "";
                $totalExpense = $summary['summary']['totalExpense'];
                $labels = [];
                $values = [];
                foreach ($summary['expenseByCategory'] as $c) {
                    $percent = $totalExpense > 0 ? round(($c['amount'] / $totalExpense) * 100, 1) : 0;
                    $listStr .= "- **" . $c['name'] . "**: Rp " . number_format($c['amount'], 0, ',', '.') . " ({$percent}%)\n";
                    $labels[] = $c['name'];
                    $values[] = (float)$c['amount'];
                }
                
                $chartData = [
                    'type' => 'pie',
                    'labels' => $labels,
                    'values' => $values,
                    'title' => 'Distribusi Pengeluaran Bulan Ini'
                ];

                $reply = "Halo {$user->name}!\n\n"
                    . "Berikut adalah ringkasan pengeluaran bulanan kamu berdasarkan kategori:\n\n" 
                    . $listStr 
                    . "\n📉 **Total Pengeluaran Bulan Ini**: **Rp " . number_format($totalExpense, 0, ',', '.') . "**\n"
                    . "📈 **Total Pemasukan Bulan Ini**: **Rp " . number_format($summary['summary']['totalIncome'], 0, ',', '.') . "**\n"
                    . "💰 **Sisa Saldo Bersih (Net)**: **Rp " . number_format($summary['summary']['net'], 0, ',', '.') . "**\n\n"
                    . "Kategori pengeluaran terbesar kamu adalah **" . ($summary['topExpenseCategory'] ?? 'Tidak ada') . "** dengan total **Rp " . number_format($summary['topExpenseAmount'], 0, ',', '.') . "**. Coba kurangi pengeluaran untuk kategori ini untuk berhemat!\n\n"
                    . "Berikut adalah grafik visualisasi distribusi pengeluaran Anda:\n\n"
                    . "[CHART_DATA: " . json_encode($chartData) . "]";
            }
            return response()->json(['reply' => $reply]);
        }

        // 5. Tips / Hemat / Saran / Strategi
        if (str_contains($msgLower, 'tips') || str_contains($msgLower, 'hemat') || str_contains($msgLower, 'saran') || str_contains($msgLower, 'strategi') || str_contains($msgLower, 'cara')) {
            $reply = "Halo {$user->name}!\n\n"
                . "Berikut adalah **3 strategi hemat praktis** minggu ini untuk meningkatkan kesehatan finansial Anda:\n\n"
                . "1. **Terapkan Aturan 24 Jam**: Tunda pembelian barang non-kebutuhan selama 24 jam. Jika setelah 24 jam Anda masih merasa sangat butuh, baru pertimbangkan untuk membelinya. Seringkali keinginan belanja impulsif hilang dalam 24 jam.\n"
                . "2. **Buat Budget Ketat untuk Kategori Terbesar**: Batasi pengeluaran harian atau mingguan Anda khususnya pada kategori makanan/hiburan.\n"
                . "3. **Amankan 10-20% Tabungan di Awal**: Begitu menerima pemasukan atau gajian, langsung transfer minimal 10% ke rekening tabungan khusus dan jangan disentuh.\n\n"
                . "Semoga tips ini membantu Anda mengelola keuangan dengan lebih bijak!";
            return response()->json(['reply' => $reply]);
        }

        $reply = "Halo {$user->name}! Saya Finansialin AI. Saat ini server Gemini API sedang bermasalah/kuota habis. Namun, saya tetap bisa membantu Anda membaca data keuangan lokal Anda secara langsung!\n\n"
            . "Cobalah tanyakan hal-hal berikut:\n"
            . "- **Berapa saldo dompet saya saat ini?** (ketik 'saldo' / 'dompet')\n"
            . "- **Tampilkan transaksi terakhir** (ketik 'transaksi' / 'riwayat')\n"
            . "- **Bagaimana status budget belanja saya?** (ketik 'budget' / 'anggaran')\n"
            . "- **Kategori apa dengan pengeluaran terbesar?** (ketik 'analisis' / 'pengeluaran terbesar')\n"
            . "- **Berikan saya tips hemat keuangan** (ketik 'tips hemat')";
        return response()->json(['reply' => $reply]);
    }
    // Fungsi khusus internal: Status Budget
    public function internalGetBudgetStatus(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $month = $request->query('month');
        $year = $request->query('year');

        if (!$userId) return response()->json(['error' => 'user_id is required'], 400);

        try {
            $status = $this->insightService->getBudgetStatus(
                (int) $userId, 
                $month ? (int)$month : null, 
                $year ? (int)$year : null
            );
            return response()->json(['status' => 'success', 'data' => $status]);
        } catch (\Exception $e) {
            Log::error('Internal API Budget Error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Gagal mengambil data budget'], 500);
        }
    }

    // Fungsi khusus internal: Analitik Bulanan (Pengeluaran per kategori)
    public function internalGetMonthlyAnalytics(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $month = $request->query('month');
        $year = $request->query('year');

        if (!$userId) return response()->json(['error' => 'user_id is required'], 400);

        try {
            $analytics = $this->insightService->getMonthlyAnalytics(
                (int) $userId, 
                $month ? (int)$month : null, 
                $year ? (int)$year : null
            );
            return response()->json(['status' => 'success', 'data' => $analytics]);
        } catch (\Exception $e) {
            Log::error('Internal API Analytics Error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Gagal mengambil analitik bulanan'], 500);
        }
    }

    // Fungsi khusus internal: Tren Pengeluaran
    public function internalGetSpendingTrend(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $months = $request->query('months', 3); // Default 3 bulan

        if (!$userId) return response()->json(['error' => 'user_id is required'], 400);

        try {
            $trend = $this->insightService->getSpendingTrend((int) $userId, (int) $months);
            return response()->json(['status' => 'success', 'data' => $trend]);
        } catch (\Exception $e) {
            Log::error('Internal API Trend Error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Gagal mengambil data tren pengeluaran'], 500);
        }
    }

    // Fungsi khusus internal: Profil Finansial User (Aset & Utang)
    public function internalGetUserFinancialProfile(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) return response()->json(['error' => 'user_id is required'], 400);

        try {
            $profile = $this->insightService->getUserFinancialProfile((int) $userId);
            return response()->json(['status' => 'success', 'data' => $profile]);
        } catch (\Exception $e) {
            Log::error('Internal API Profile Error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Gagal mengambil data profil finansial'], 500);
        }
    }

    // Fungsi khusus internal: Target Tabungan (Savings Goals)
    public function internalGetSavingsGoals(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) return response()->json(['error' => 'user_id is required'], 400);

        try {
            $goals = $this->insightService->getSavingsGoals((int) $userId);
            return response()->json(['status' => 'success', 'data' => $goals]);
        } catch (\Exception $e) {
            Log::error('Internal API Savings Error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Gagal mengambil data target tabungan'], 500);
        }
    }

    public function debugModels(): JsonResponse
    {
        $apiKey = trim((string) config('services.gemini.api_key', ''));
        $url = "https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}";
        
        try {
            $response = Http::get($url);
            Log::info('Gemini ListModels Response', [
                'status' => $response->status(),
                'data' => $response->json()
            ]);
            return response()->json($response->json());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
