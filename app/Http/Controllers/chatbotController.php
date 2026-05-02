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
use Throwable;

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

        try {
            $response = Http::attach(
                'receiptImage', 
                file_get_contents($file->getRealPath()), 
                $file->getClientOriginalName()
            )->post('http://localhost:8000/predict/ocr');

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
                'error' => $e->getMessage()
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

        try {
            $response = Http::post('http://localhost:8000/predict/budget', $request->all());

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
                'error' => $e->getMessage()
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
                ]
            ]
        ];

        $systemInstruction = [
            'parts' => [
                ['text' => 'Kamu adalah Finansialin AI, asisten pribadi virtual yang proaktif, cerdas, dan empatik. Kamu memiliki memori percakapan berkat history yang diberikan. Jawab dengan gaya bahasa kasual (aku/kamu). Gunakan tools yang tersedia untuk merespons akurat terkait keuangan pengguna. Jawab juga pertanyaan sapaan atau trivia dengan luwes tanpa memaksakan diri menjadi kaku. Bila perlu, berikan saran penghematan atau peringatan budget sesuai data riil mereka.']
            ]
        ];

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
            'tools' => $tools,
            'contents' => $contents
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

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

        try {
            $http = Http::withHeaders(['Content-Type' => 'application/json'])
                ->withOptions(['verify' => $verify])
                ->timeout(30);

            $response = $http->post($url, $payload);
            $data = $response->json();
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
            } elseif ($functionName === 'getRecentTransactions') {
                $functionResult = $service->getRecentTransactions($userId, $args['limit'] ?? 5);
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
                                'content' => $functionResult
                            ]
                        ]
                    ]
                ]
            ];

            // Re-request ke Gemini dengan history dan data balasan
            $secondResponse = $http->post($url, $payload);

            $data = $secondResponse->json();
        }

        $finalAnswer = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Maaf, aku tidak bisa memproses permintaan saat ini.';

        return response()->json([
            'reply' => $finalAnswer
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
    
}
}
