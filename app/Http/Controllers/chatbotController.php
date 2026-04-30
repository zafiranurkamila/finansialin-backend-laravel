<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialInsightService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ChatbotController extends Controller
{
    private FinancialInsightService $insightService;

    // Semua nama fungsi yang dikenali AI
    private const AVAILABLE_FUNCTIONS = [
        'getWalletBalances',
        'getMonthlyAnalytics',
        'getBudgetStatus',
        'getRecentTransactions',
        'getSpendingTrend',
        'getUserFinancialProfile',
        'getSavingsGoals',
    ];

    public function __construct(FinancialInsightService $insightService)
    {
        $this->insightService = $insightService;
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
        $validator = Validator::make($request->all(), [
            'receiptImage' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('receiptImage');
        if (!$file instanceof UploadedFile) {
            return response()->json(['message' => 'receiptImage is required'], 422);
        }

        $serviceUrl = rtrim((string) config('services.ocr.service_url', 'http://127.0.0.1:8001'), '/');

        try {
            $response = Http::timeout(120)->attach(
                'receiptImage',
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            )->post($serviceUrl . '/predict/ocr');

            if ($response->successful()) {
                return response()->json($response->json(), 200);
            }

            return response()->json([
                'message' => 'AI Service Error',
                'details' => $response->json(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to connect to AI service',
                'error' => $e->getMessage(),
                'service_url' => $serviceUrl,
            ], 500);
        }
    }

    public function predictEarlyWarning(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer'],
            'budget' => ['required', 'numeric'],
            'payday_date' => ['required', 'integer'],
            'expenses' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $serviceUrl = rtrim((string) config('services.ocr.service_url', 'http://127.0.0.1:8001'), '/');

        try {
            $response = Http::timeout(120)->post($serviceUrl . '/predict/budget', $request->all());

            if ($response->successful()) {
                return response()->json($response->json(), 200);
            }

            return response()->json([
                'message' => 'AI Service Error',
                'details' => $response->json(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to connect to AI service',
                'error' => $e->getMessage(),
                'service_url' => $serviceUrl,
            ], 500);
        }
    }

    public function dashboardSummary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $now = CarbonImmutable::now('UTC');

        $totalIncome = (float) Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('type', 'income')
            ->sum('amount');

        $totalExpense = (float) Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('type', 'expense')
            ->sum('amount');

        $totalBalance = $totalIncome - $totalExpense;

        $incomeChartData = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthDate = $now->subMonths($i)->startOfMonth();

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

        return response()->json([
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
            'message'   => 'required|string|max:2000',
            'history'   => 'nullable|array',
            'history.*.role' => 'required_with:history|in:user,model',
            'history.*.text' => 'required_with:history|string',
        ]);

        $message = trim($request->input('message'));
        $history = $request->input('history', []);

        $apiKey = config('services.gemini.api_key');
        if (empty($apiKey)) {
            return response()->json(['reply' => 'Konfigurasi AI belum lengkap. Hubungi administrator.'], 500);
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

        // Bangun system instruction yang kaya konteks
        $systemInstruction = $this->buildSystemInstruction($user);

        // Definisi semua tools
        $tools = $this->buildToolDefinitions();

        // Bangun contents dari history + pesan baru
        $contents = $this->buildContents($history, $message);

        $payload = [
            'system_instruction' => $systemInstruction,
            'tools'              => $tools,
            'contents'           => $contents,
            'generationConfig'   => [
                'temperature'     => 0.7,
                'maxOutputTokens' => 1024,
            ],
        ];

        try {
            // === TURN 1: Kirim ke Gemini ===
            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Gemini API error', ['status' => $response->status(), 'body' => $response->body()]);
                return response()->json(['reply' => 'Maaf, layanan AI sedang tidak tersedia. Coba lagi sebentar ya.'], 503);
            }

            $data = $response->json();

            // === LOOP: Tangani function call (bisa lebih dari satu iterasi) ===
            $maxIterations = 5; // Batas aman agar tidak infinite loop
            $iteration = 0;

            while ($iteration < $maxIterations) {
                $iteration++;
                $candidate = $data['candidates'][0] ?? null;

                if (!$candidate) {
                    break;
                }

                // Kumpulkan semua functionCall dari parts (Gemini bisa kirim beberapa sekaligus)
                $parts = $candidate['content']['parts'] ?? [];
                $functionCalls = array_filter($parts, fn($p) => isset($p['functionCall']));

                // Tidak ada function call → ini adalah jawaban final
                if (empty($functionCalls)) {
                    break;
                }

                // Tambahkan respons model (yg berisi function call) ke contents
                $payload['contents'][] = $candidate['content'];

                // Eksekusi semua function call dan kumpulkan hasilnya
                $functionResponseParts = [];
                foreach ($functionCalls as $part) {
                    $functionName = $part['functionCall']['name'];
                    $args = $part['functionCall']['args'] ?? [];

                    // Keamanan: hanya eksekusi fungsi yang terdaftar
                    if (!in_array($functionName, self::AVAILABLE_FUNCTIONS, true)) {
                        Log::warning("Gemini requested unknown function: {$functionName}");
                        continue;
                    }

                    $result = $this->executeFunction($functionName, $userId, $args);

                    $functionResponseParts[] = [
                        'functionResponse' => [
                            'name'     => $functionName,
                            'response' => ['content' => $result],
                        ],
                    ];
                }

                if (empty($functionResponseParts)) {
                    break;
                }

                // Tambahkan semua function responses sekaligus ke contents
                $payload['contents'][] = [
                    'role'  => 'user',
                    'parts' => $functionResponseParts,
                ];

                // Kirim lagi ke Gemini dengan data hasil fungsi
                $response = Http::timeout(30)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($url, $payload);

                if (!$response->successful()) {
                    Log::error('Gemini API error (turn 2+)', ['status' => $response->status()]);
                    break;
                }

                $data = $response->json();
            }

            $finalAnswer = $data['candidates'][0]['content']['parts'][0]['text']
                ?? 'Maaf, aku tidak bisa memproses permintaan itu sekarang. Coba tanya dengan cara lain ya.';

            return response()->json(['reply' => $finalAnswer]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Gemini connection timeout', ['error' => $e->getMessage()]);
            return response()->json(['reply' => 'Koneksi ke AI timeout. Coba lagi dalam beberapa detik ya.'], 504);
        } catch (\Exception $e) {
            Log::error('Chatbot unexpected error', ['error' => $e->getMessage()]);
            return response()->json(['reply' => 'Terjadi kesalahan tak terduga. Tim kami sedang dicek.'], 500);
        }
    }

    // =========================================================================
    // SYSTEM INSTRUCTION — Otak kepribadian & konteks AI
    // =========================================================================
    private function buildSystemInstruction(User $user): array
    {
        $now = Carbon::now('Asia/Jakarta');
        $userName = $user->name ?? 'Pengguna';
        $userEmail = $user->email ?? '';
        $dayName = $now->translatedFormat('l'); // Senin, Selasa, dst
        $dateFormatted = $now->format('d F Y');
        $timeFormatted = $now->format('H:i') . ' WIB';

        $systemText = <<<PROMPT
Kamu adalah **Finansialin AI** — asisten keuangan pribadi yang cerdas, empatik, dan proaktif milik aplikasi Finansialin.

## Identitas & Konteks Sesi
- Nama pengguna: **{$userName}** ({$userEmail})
- Waktu saat ini: {$dayName}, {$dateFormatted} pukul {$timeFormatted}
- Mata uang: Rupiah (IDR). Selalu tampilkan angka dalam format "Rp1.250.000" (titik sebagai pemisah ribuan).

## Kepribadian & Gaya Bahasa
- Gunakan bahasa **Indonesia** yang **kasual dan hangat** (aku/kamu).
- Bersikap seperti teman yang paham keuangan, bukan robot atau konsultan formal.
- Boleh pakai emoji secukupnya untuk membuat percakapan lebih hidup 😊
- Berikan respons yang **ringkas dan actionable** — hindari paragraf panjang yang bertele-tele.
- Kalau user menyapa atau bercanda, balas dengan natural sebelum masuk ke konteks keuangan.

## Aturan Utama (WAJIB DIIKUTI)
1. **Selalu gunakan tools** untuk menjawab pertanyaan yang menyangkut data keuangan {$userName} (saldo, transaksi, budget, dll). JANGAN mengarang angka.
2. Jika pertanyaan membutuhkan beberapa data sekaligus (misal: saldo + transaksi terbaru), panggil **beberapa tools sekaligus** dalam satu respons.
3. Jika kamu tidak yakin perlu panggil tool atau tidak, **lebih baik panggil** daripada menebak.
4. Setelah mendapat data, **interpretasikan** angkanya — jangan hanya memuntahkan data mentah.
5. Berikan **rekomendasi konkret** yang spesifik ke situasi {$userName}, bukan saran generik.
6. Jika ada anomali (pengeluaran melonjak, budget hampir habis), **proaktif mengingatkan** meski tidak ditanya.

## Kemampuan Keuangan yang Bisa Kamu Lakukan
- Cek saldo semua dompet/rekening
- Analisis pengeluaran & pemasukan bulanan per kategori
- Monitor status budget dan peringatan overbudget
- Review transaksi terbaru dan deteksi pengeluaran tidak wajar
- Analisis tren pengeluaran 3-6 bulan terakhir
- Lihat goals/target tabungan dan progresnya
- Memberikan rekomendasi penghematan yang personal dan spesifik
- Menjawab pertanyaan umum seputar literasi keuangan

## Contoh Respons yang Baik
- ❌ Buruk: "Pengeluaranmu bulan ini adalah Rp2.000.000"
- ✅ Baik: "Bulan ini kamu sudah keluar Rp2.000.000 — naik 15% dari bulan lalu 📈. Kategori terbesar ada di Makanan (Rp800k). Kalau mau hemat, coba kurangi frekuensi makan di luar dari 10x jadi 6x minggu ini."

Ingat: kamu bukan sekadar pembaca data — kamu adalah partner keuangan personal {$userName}!
PROMPT;

        return [
            'parts' => [['text' => $systemText]],
        ];
    }

    // =========================================================================
    // TOOL DEFINITIONS — Semua kemampuan mengakses data
    // =========================================================================
    private function buildToolDefinitions(): array
    {
        return [
            [
                'functionDeclarations' => [
                    [
                        'name'        => 'getWalletBalances',
                        'description' => 'Ambil daftar semua dompet/rekening/sumber dana milik pengguna beserta saldo terkini. Gunakan ini saat user bertanya tentang saldo, dompet, tabungan, atau kondisi keuangan saat ini.',
                        'parameters'  => [
                            'type'       => 'OBJECT',
                            'properties' => new \stdClass(),
                        ],
                    ],
                    [
                        'name'        => 'getMonthlyAnalytics',
                        'description' => 'Ambil ringkasan analitik pengeluaran dan pemasukan per kategori pada bulan dan tahun tertentu. Gunakan ini saat user bertanya tentang pengeluaran, pemasukan, atau ringkasan keuangan bulan tertentu.',
                        'parameters'  => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'month' => [
                                    'type'        => 'INTEGER',
                                    'description' => 'Nomor bulan (1-12). Kosongkan untuk bulan saat ini.',
                                ],
                                'year' => [
                                    'type'        => 'INTEGER',
                                    'description' => 'Tahun (contoh: 2025). Kosongkan untuk tahun saat ini.',
                                ],
                            ],
                        ],
                    ],
                    [
                        'name'        => 'getBudgetStatus',
                        'description' => 'Ambil status semua budget pengguna — termasuk batas, sudah terpakai berapa, dan apakah ada yang overbudget atau mendekati batas. Gunakan saat user tanya tentang budget, limit pengeluaran, atau peringatan keuangan.',
                        'parameters'  => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'month' => [
                                    'type'        => 'INTEGER',
                                    'description' => 'Nomor bulan (1-12). Kosongkan untuk bulan saat ini.',
                                ],
                                'year' => [
                                    'type'        => 'INTEGER',
                                    'description' => 'Tahun. Kosongkan untuk tahun saat ini.',
                                ],
                            ],
                        ],
                    ],
                    [
                        'name'        => 'getRecentTransactions',
                        'description' => 'Ambil riwayat transaksi terbaru pengguna (pengeluaran maupun pemasukan). Gunakan saat user bertanya tentang transaksi terakhir, pembelian tertentu, atau ingin tahu uangnya kemana.',
                        'parameters'  => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'limit' => [
                                    'type'        => 'INTEGER',
                                    'description' => 'Jumlah transaksi yang diambil (default: 5, maksimal: 20).',
                                ],
                                'type' => [
                                    'type'        => 'STRING',
                                    'description' => 'Filter tipe: "income" untuk pemasukan, "expense" untuk pengeluaran, kosongkan untuk semua.',
                                    'enum'        => ['income', 'expense'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'name'        => 'getSpendingTrend',
                        'description' => 'Ambil tren pengeluaran beberapa bulan terakhir untuk melihat pola naik/turun. Gunakan saat user bertanya tentang tren, pola pengeluaran, atau perbandingan antar bulan.',
                        'parameters'  => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'months' => [
                                    'type'        => 'INTEGER',
                                    'description' => 'Jumlah bulan ke belakang yang ingin dilihat (default: 3, maksimal: 6).',
                                ],
                            ],
                        ],
                    ],
                    [
                        'name'        => 'getUserFinancialProfile',
                        'description' => 'Ambil ringkasan profil keuangan pengguna: total aset, total utang, net worth, dan rasio tabungan. Gunakan untuk pertanyaan umum tentang kondisi keuangan keseluruhan atau rekomendasi personal.',
                        'parameters'  => [
                            'type'       => 'OBJECT',
                            'properties' => new \stdClass(),
                        ],
                    ],
                    [
                        'name'        => 'getSavingsGoals',
                        'description' => 'Ambil daftar goals/target tabungan pengguna dan progresnya. Gunakan saat user bertanya tentang target keuangan, goals, atau seberapa dekat mereka mencapai tujuan tabungan.',
                        'parameters'  => [
                            'type'       => 'OBJECT',
                            'properties' => new \stdClass(),
                        ],
                    ],
                ],
            ],
        ];
    }

    // =========================================================================
    // BUILD CONTENTS — Susun history percakapan
    // =========================================================================
    private function buildContents(array $history, string $newMessage): array
    {
        $contents = [];

        foreach ($history as $item) {
            if (!isset($item['role'], $item['text'])) {
                continue;
            }
            // Normalisasi: frontend kadang kirim 'assistant', Gemini butuh 'model'
            $role = $item['role'] === 'assistant' ? 'model' : $item['role'];

            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => (string) $item['text']]],
            ];
        }

        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $newMessage]],
        ];

        return $contents;
    }

    // =========================================================================
    // FUNCTION EXECUTOR — Router ke FinancialInsightService
    // =========================================================================
    private function executeFunction(string $name, int $userId, array $args): array
    {
        try {
            $result = match ($name) {
                'getWalletBalances'      => $this->insightService->getWalletBalances($userId),
                'getMonthlyAnalytics'    => $this->insightService->getMonthlyAnalytics(
                    $userId,
                    isset($args['month']) ? (int) $args['month'] : null,
                    isset($args['year'])  ? (int) $args['year']  : null,
                ),
                'getBudgetStatus'        => $this->insightService->getBudgetStatus(
                    $userId,
                    isset($args['month']) ? (int) $args['month'] : null,
                    isset($args['year'])  ? (int) $args['year']  : null,
                ),
                'getRecentTransactions'  => $this->insightService->getRecentTransactions(
                    $userId,
                    min((int) ($args['limit'] ?? 5), 20),
                    $args['type'] ?? null,
                ),
                'getSpendingTrend'       => $this->insightService->getSpendingTrend(
                    $userId,
                    min((int) ($args['months'] ?? 3), 6),
                ),
                'getUserFinancialProfile' => $this->insightService->getUserFinancialProfile($userId),
                'getSavingsGoals'        => $this->insightService->getSavingsGoals($userId),
                default                  => ['error' => 'Fungsi tidak ditemukan'],
            };

            // Pastikan selalu return array (bukan Collection/object mentah)
            if ($result instanceof \Illuminate\Support\Collection) {
                return $result->toArray();
            }

            return is_array($result) ? $result : ['data' => $result];

        } catch (\Exception $e) {
            Log::error("FinancialInsightService error: {$name}", [
                'userId' => $userId,
                'args'   => $args,
                'error'  => $e->getMessage(),
            ]);

            return ['error' => 'Data tidak tersedia saat ini', 'function' => $name];
        }
    }
}