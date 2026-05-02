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

        // Validasi input dari frontend (kita hilangkan validasi 'history' karena sekarang di-handle Python)
        $request->validate([
            'message'    => 'required|string|max:2000',
            'session_id' => 'nullable|string', 
        ]);

        $message = trim($request->input('message'));
        
        // Generate session_id sementara jika frontend belum mengirimkannya
        // Format: session_{userId}_{tanggal_hari_ini}
        $sessionId = $request->input('session_id', 'session_' . $userId . '_' . now()->format('Ymd'));

        // URL ke service Python (menggunakan config yang sama dengan OCR)
        $serviceUrl = rtrim((string) config('services.ocr.service_url', 'http://127.0.0.1:8001'), '/');

        try {
            // STEP 1: Laravel hanya menjadi kurir yang meneruskan data ke Python
            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($serviceUrl . '/chat', [
                    'user_id'    => $userId,
                    'session_id' => $sessionId,
                    'message'    => $message,
                ]);

            if (!$response->successful()) {
                Log::error('Python AI Service error', [
                    'status' => $response->status(), 
                    'body' => $response->body()
                ]);
                return response()->json(['reply' => 'Maaf, layanan asisten sedang tidak tersedia.'], 503);
            }

            $data = $response->json();

            // Kembalikan balasan dari Python ke Frontend
            return response()->json([
                'reply' => $data['reply'] ?? 'Tidak ada balasan dari AI.',
                'type'  => $data['type'] ?? 'text'
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Python connection timeout', ['error' => $e->getMessage()]);
            return response()->json(['reply' => 'Koneksi ke asisten AI timeout. Coba sebentar lagi ya.'], 504);
        } catch (\Exception $e) {
            Log::error('Chatbot unexpected error', ['error' => $e->getMessage()]);
            return response()->json(['reply' => 'Terjadi kesalahan sistem internal.'], 500);
        }
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

    // Fungsi khusus untuk diakses oleh service Python AI
    public function internalGetBalance(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        
        if (!$userId) {
            return response()->json(['error' => 'user_id is required'], 400);
        }

        try {
            // Memanggil service yang sudah ada di aplikasimu untuk menghitung saldo
            $balances = $this->insightService->getWalletBalances((int) $userId);
            
            return response()->json([
                'status' => 'success',
                'data' => $balances
            ]);
        } catch (\Exception $e) {
            Log::error('Internal API Balance Error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Gagal mengambil data saldo'], 500);
        }
    }
    public function internalGetRecentTransactions(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $limit = $request->query('limit', 5); // Default ambil 5 transaksi kalau tidak dispesifikasikan

        if (!$userId) {
            return response()->json(['error' => 'user_id is required'], 400);
        }

        try {
            // Memanggil service yang sudah ada untuk mengambil transaksi terakhir
            $transactions = $this->insightService->getRecentTransactions((int) $userId, (int) $limit);
            
            return response()->json([
                'status' => 'success',
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
            Log::error('Internal API Recent Transactions Error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Gagal mengambil data riwayat transaksi'], 500);
        }
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