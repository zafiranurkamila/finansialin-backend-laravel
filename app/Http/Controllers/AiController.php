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
        if ($file === null) {
            return response()->json(['message' => 'receiptImage is required'], 422);
        }

        $aiServiceUrl = rtrim((string) config('services.ocr.service_url', 'http://127.0.0.1:8001'), '/');

        try {
            $response = Http::timeout(60)->attach(
                'receiptImage',
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            )->post("{$aiServiceUrl}/predict/ocr");

            if ($response->successful()) {
                return response()->json($response->json(), 200);
            }

            return response()->json([
                'message' => 'AI Service Error',
                'details' => $response->json(),
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to connect to AI service. Pastikan Python AI service berjalan di port yang benar (OCR_AI_SERVICE_URL di .env).',
                'error'   => $e->getMessage(),
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

        $aiServiceUrl = rtrim((string) config('services.ocr.service_url', 'http://127.0.0.1:8001'), '/');

        try {
            $response = Http::timeout(30)->post("{$aiServiceUrl}/predict/budget", $request->all());

            if ($response->successful()) {
                return response()->json($response->json(), 200);
            }

            return response()->json([
                'message' => 'AI Service Error',
                'details' => $response->json(),
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to connect to AI service. Pastikan Python AI service berjalan di port yang benar (OCR_AI_SERVICE_URL di .env).',
                'error'   => $e->getMessage(),
            ], 500);
        }
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
1. Memberikan analisis keuangan yang sangat MENDALAM dan TERSTRUKTUR.
2. Gunakan tools secara agresif untuk mendapatkan data riil sebelum memberikan saran.
3. Berikan saran strategis: Jika pengeluaran besar di satu kategori, berikan 3 langkah konkret untuk menguranginya.
4. Selalu sapa pengguna dengan nama mereka: {$user->name}.

GAYA KOMUNIKASI (SANGAT PENTING):
- JANGAN PERNAH memberikan jawaban singkat satu paragraf. Jawabanmu harus minimal 3-4 paragraf atau list yang mendetail.
- Gunakan format Markdown yang kaya (Bold, Italic, Tables, Lists).
- Jika pengguna meminta grafik, tren, atau perbandingan data yang cocok divisualisasikan, sisipkan data grafik di akhir jawabanmu dengan format berikut:
  [CHART_DATA: {"type": "line", "labels": ["Jan", "Feb", "Mar"], "values": [100000, 200000, 150000], "title": "Tren Pengeluaran"}]
  (Gunakan type: 'line' untuk tren, 'bar' untuk perbandingan kategori, 'pie' untuk distribusi pengeluaran).
- Hubungkan satu data dengan data lainnya (misal: 'Saldo dompet kamu cukup besar, tapi budget makanan kamu sudah hampir habis').

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
            return response()->json([
                'message' => 'Gemini API key is missing. Set GEMINI_API_KEY in backend .env, then restart Laravel server.',
            ], 500);
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
            'gemini-2.0-flash', 
            'gemini-1.5-flash',
            'gemini-1.5-flash-8b',
            'gemini-1.5-pro',
            'gemini-pro',
        ];

        $lastError = null;
        $attempted = [];
        $maxAttempts = 8; 

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
                ->timeout(60)
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
            $isQuotaError = str_contains(strtolower($lastError), 'quota') || str_contains(strtolower($lastError), 'exhausted');
            
            return response()->json([
                'message' => $isQuotaError 
                    ? 'Batas penggunaan API Gemini (Quota) telah tercapai. Silakan coba lagi beberapa saat lagi atau gunakan API Key lain.'
                    : 'Layanan AI sedang tidak tersedia setelah beberapa percobaan.',
                'last_error' => $lastError,
                'attempted' => $attempted
            ], $isQuotaError ? 429 : 502);
        }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'ssl certificate problem')) {
                return response()->json([
                    'message' => 'SSL certificate validation failed when calling Gemini. Set GEMINI_CA_BUNDLE in backend .env to your cacert.pem path and restart Laravel.',
                    'details' => $msg,
                ], 502);
            }

            return response()->json([
                'message' => 'Failed to connect to Gemini service.',
                'details' => $msg,
            ], 502);
        }

        if (!isset($data['candidates'][0]['content']['parts'])) {
            Log::error('Gemini API response missing parts', [
                'details' => $data,
            ]);
            return response()->json([
                'message' => 'Layanan AI sedang tidak tersedia. Silakan coba beberapa saat lagi.',
                'details' => $data,
            ], 502);
        }

        // ── Scan ALL parts for a functionCall ────────────────────────────────
        // gemini-2.5-flash (thinking model) may emit a text preamble in parts[0]
        // and put the actual functionCall in parts[1] or later.
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

            // Round-trip 2: append model turn (with functionCall) + tool result
            // Fix: PHP json_decode converts {} → [] (empty array). When re-encoded,
            // [] serialises as a JSON list, but Gemini requires {} (object) for `args`.
            // We must walk the model content and restore any empty-array `args` to stdClass.
            $modelContent = $data['candidates'][0]['content'];
            foreach ($modelContent['parts'] as &$p) {
                if (isset($p['functionCall']['args']) && is_array($p['functionCall']['args']) && count($p['functionCall']['args']) === 0) {
                    $p['functionCall']['args'] = new \stdClass();
                }
            }
            unset($p);

            $payload['contents'][] = $modelContent;
            $payload['contents'][] = [
                'role'  => 'tool', // 'tool' is required by Gemini v1beta for functionResponse
                'parts' => [
                    [
                        'functionResponse' => [
                            'name'     => $functionName,
                            'response' => ['content' => $functionResult],
                        ]
                    ]
                ],
            ];

            // Second Gemini request — now it has real data to compose a final reply
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
                    ->timeout(60)
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

                    // If rate limited or server error, wait a bit and try next model/retry
                    if (in_array($status, [429, 503, 504], true)) {
                        sleep(1);
                        continue;
                    }

                    // If model not found, try next one immediately
                    if ($status === 404) {
                        continue;
                    }

                    break;
                }

                if (!$secondResponse || $secondResponse->failed()) {
                    return response()->json([
                        'message' => 'Layanan AI sedang tidak tersedia. Silakan coba beberapa saat lagi.',
                        'details' => $data,
                    ], 502);
                }
            } catch (Throwable $e) {
                return response()->json([
                    'message' => 'Failed on second Gemini request.',
                    'details' => $e->getMessage(),
                ], 502);
            }
        }

        if (!isset($data['candidates'][0]['content']['parts'])) {
            Log::error('Gemini API response missing final parts', [
                'details' => $data,
            ]);
            return response()->json([
                'message' => 'Layanan AI sedang tidak tersedia. Silakan coba beberapa saat lagi.',
                'details' => $data,
            ], 502);
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

