<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\MonthlyCategoryAnalytic;
use App\Models\MonthlyBudgetUsage;
use App\Models\Resource;
use App\Models\Transaction;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

class FinancialInsightService
{
    // =========================================================================
    // 1. Wallet Balances
    // =========================================================================
    /**
     * Ambil semua dompet/rekening user beserta saldonya.
     */
    public function getWalletBalances(int $userId): array
    {
        $wallets = Resource::where('idUser', $userId)
            ->select('source as wallet_name', 'balance')
            ->get();

        $totalBalance = $wallets->sum('balance');

        return [
            'wallets'       => $wallets->toArray(),
            'total_balance' => round($totalBalance, 2),
            'wallet_count'  => $wallets->count(),
            'currency'      => 'IDR',
        ];
    }

    // =========================================================================
    // 2. Monthly Analytics
    // =========================================================================
    /**
     * Ambil ringkasan analitik bulanan per kategori.
     */
    public function getMonthlyAnalytics(int $userId, ?int $month = null, ?int $year = null): array
    {
        $now   = Carbon::now();
        $month = $month ?? $now->month;
        $year  = $year  ?? $now->year;

        $analytics = MonthlyCategoryAnalytic::where('user_id', $userId)
            ->where('month', $month)
            ->where('year', $year)
            ->get();

        $totalIncome  = $analytics->where('type', 'income')->sum('total_amount');
        $totalExpense = $analytics->where('type', 'expense')->sum('total_amount');
        $netBalance   = $totalIncome - $totalExpense;
        $savingsRate  = $totalIncome > 0 ? round(($netBalance / $totalIncome) * 100, 1) : 0;

        // Top 3 kategori pengeluaran terbesar
        $topExpenseCategories = $analytics
            ->where('type', 'expense')
            ->sortByDesc('total_amount')
            ->take(3)
            ->values()
            ->toArray();

        return [
            'period'                 => Carbon::create($year, $month)->format('F Y'),
            'month'                  => $month,
            'year'                   => $year,
            'total_income'           => round($totalIncome, 2),
            'total_expense'          => round($totalExpense, 2),
            'net_balance'            => round($netBalance, 2),
            'savings_rate_percent'   => $savingsRate,
            'top_expense_categories' => $topExpenseCategories,
            'all_categories'         => $analytics->toArray(),
            'currency'               => 'IDR',
        ];
    }

    // =========================================================================
    // 3. Budget Status
    // =========================================================================
    /**
     * Ambil status semua budget user — berapa terpakai, sisa, dan level urgensi.
     */
    public function getBudgetStatus(int $userId, ?int $month = null, ?int $year = null): array
    {
        $now   = Carbon::now();
        $month = $month ?? $now->month;
        $year  = $year  ?? $now->year;

        $budgets = MonthlyBudgetUsage::where('user_id', $userId)
            ->where('month', $month)
            ->where('year', $year)
            ->get();

        // Tambahkan field tambahan untuk AI
        $enriched = $budgets->map(function ($b) {
            $usedPercent = $b->budget_amount > 0
                ? round(($b->used_amount / $b->budget_amount) * 100, 1)
                : 0;

            // Level urgensi: safe / warning / critical / overbudget
            $urgency = match (true) {
                $usedPercent >= 100 => 'overbudget',
                $usedPercent >= 80  => 'critical',
                $usedPercent >= 60  => 'warning',
                default             => 'safe',
            };

            return array_merge($b->toArray(), [
                'used_percent' => $usedPercent,
                'remaining'    => round($b->budget_amount - $b->used_amount, 2),
                'urgency'      => $urgency,
            ]);
        });

        $overbudgetCount = $enriched->where('urgency', 'overbudget')->count();
        $criticalCount   = $enriched->where('urgency', 'critical')->count();

        return [
            'period'           => Carbon::create($year, $month)->format('F Y'),
            'month'            => $month,
            'year'             => $year,
            'budgets'          => $enriched->values()->toArray(),
            'total_budgets'    => $enriched->count(),
            'overbudget_count' => $overbudgetCount,
            'critical_count'   => $criticalCount,
            'summary'          => $overbudgetCount > 0
                ? "{$overbudgetCount} budget sudah overbudget"
                : ($criticalCount > 0 ? "{$criticalCount} budget mendekati batas" : 'Semua budget masih aman'),
            'currency'         => 'IDR',
        ];
    }

    // =========================================================================
    // 4. Recent Transactions
    // =========================================================================
    /**
     * Ambil transaksi terbaru user, dengan filter tipe opsional.
     */
    public function getRecentTransactions(int $userId, int $limit = 5, ?string $type = null): array
    {
        $limit = min($limit, 20); // Batas aman

        $query = Transaction::with('category:idCategory,name')
            ->where('idUser', $userId)
            ->orderByDesc('date');

        if ($type !== null && in_array($type, ['income', 'expense'], true)) {
            $query->where('type', $type);
        }

        $transactions = $query->limit($limit)->get()->map(function ($tx) {
            return [
                'id'          => $tx->idTransaction,
                'amount'      => round((float) $tx->amount, 2),
                'type'        => $tx->type,
                'category'    => $tx->category?->name ?? 'Uncategorized',
                'date'        => $tx->date->format('Y-m-d'),
                'time'        => $tx->date->format('H:i'),
                'description' => $tx->description ?? '-',
                'source'      => $tx->source ?? 'Manual',
            ];
        });

        $totalAmount = $transactions->sum('amount');

        return [
            'transactions'  => $transactions->values()->toArray(),
            'count'         => $transactions->count(),
            'total_amount'  => round($totalAmount, 2),
            'filter_type'   => $type ?? 'all',
            'currency'      => 'IDR',
        ];
    }

    // =========================================================================
    // 5. Spending Trend (NEW)
    // =========================================================================
    /**
     * Tren pengeluaran N bulan terakhir — untuk analisis pola keuangan.
     */
    public function getSpendingTrend(int $userId, int $months = 3): array
    {
        $months = min($months, 6);
        $now    = CarbonImmutable::now();
        $trend  = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $targetDate = $now->subMonths($i);
            $m = $targetDate->month;
            $y = $targetDate->year;

            $monthStart = $targetDate->startOfMonth();
            $monthEnd   = $targetDate->endOfMonth();

            $income = (float) Transaction::where('idUser', $userId)
                ->where('type', 'income')
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->sum('amount');

            $expense = (float) Transaction::where('idUser', $userId)
                ->where('type', 'expense')
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->sum('amount');

            // Top category bulan ini
            $topCategory = Transaction::with('category:idCategory,name')
                ->where('idUser', $userId)
                ->where('type', 'expense')
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->get()
                ->groupBy(fn($tx) => $tx->category?->name ?? 'Uncategorized')
                ->map(fn($group) => $group->sum('amount'))
                ->sortDesc()
                ->keys()
                ->first();

            $trend[] = [
                'period'       => $targetDate->format('M Y'),
                'month'        => $m,
                'year'         => $y,
                'income'       => round($income, 2),
                'expense'      => round($expense, 2),
                'net'          => round($income - $expense, 2),
                'top_category' => $topCategory ?? '-',
            ];
        }

        // Hitung perubahan pengeluaran bulan ini vs bulan lalu
        $changePercent = null;
        if (count($trend) >= 2) {
            $current  = $trend[count($trend) - 1]['expense'];
            $previous = $trend[count($trend) - 2]['expense'];
            $changePercent = $previous > 0
                ? round((($current - $previous) / $previous) * 100, 1)
                : null;
        }

        return [
            'months_analyzed'        => $months,
            'trend'                  => $trend,
            'expense_change_percent' => $changePercent, // positif = naik, negatif = turun
            'currency'               => 'IDR',
        ];
    }

    // =========================================================================
    // 6. User Financial Profile (NEW)
    // =========================================================================
    /**
     * Profil finansial ringkas: total aset, pengeluaran, net worth, savings rate.
     */
    public function getUserFinancialProfile(int $userId): array
    {
        $now = CarbonImmutable::now();

        // Total saldo semua dompet
        $totalAssets = (float) Resource::where('idUser', $userId)->sum('balance');

        // Pengeluaran & pemasukan bulan ini
        $monthStart = $now->startOfMonth();
        $monthEnd   = $now->endOfMonth();

        $incomeThisMonth = (float) Transaction::where('idUser', $userId)
            ->where('type', 'income')
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->sum('amount');

        $expenseThisMonth = (float) Transaction::where('idUser', $userId)
            ->where('type', 'expense')
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->sum('amount');

        $savingsRate = $incomeThisMonth > 0
            ? round((($incomeThisMonth - $expenseThisMonth) / $incomeThisMonth) * 100, 1)
            : 0;

        // Total transaksi sejak awal
        $firstTransaction = Transaction::where('idUser', $userId)
            ->orderBy('date')
            ->first();

        return [
            'total_assets'           => round($totalAssets, 2),
            'income_this_month'      => round($incomeThisMonth, 2),
            'expense_this_month'     => round($expenseThisMonth, 2),
            'net_this_month'         => round($incomeThisMonth - $expenseThisMonth, 2),
            'savings_rate_percent'   => $savingsRate,
            'member_since'           => $firstTransaction?->date->format('M Y') ?? 'Belum ada transaksi',
            'financial_health'       => $this->assessFinancialHealth($savingsRate, $expenseThisMonth, $incomeThisMonth),
            'currency'               => 'IDR',
        ];
    }

    // =========================================================================
    // 7. Savings Goals (NEW)
    // =========================================================================
    /**
     * Ambil goals/target tabungan user dan progresnya.
     * Sesuaikan nama model 'Goal' dengan model yang ada di project kamu.
     */
    public function getSavingsGoals(int $userId): array
    {
        // Cek apakah model Goal ada
        // Ganti 'App\Models\Goal' dengan model yang sesuai di project kamu
        if (!class_exists(\App\Models\Goal::class)) {
            return [
                'goals'       => [],
                'total_goals' => 0,
                'note'        => 'Fitur savings goals belum tersedia',
            ];
        }

        $goals = \App\Models\Goal::where('idUser', $userId)->get()->map(function ($goal) {
            $progress = $goal->target_amount > 0
                ? round(($goal->current_amount / $goal->target_amount) * 100, 1)
                : 0;

            $remaining = max(0, $goal->target_amount - $goal->current_amount);

            return [
                'id'              => $goal->idGoal ?? $goal->id,
                'name'            => $goal->name,
                'target_amount'   => round((float) $goal->target_amount, 2),
                'current_amount'  => round((float) $goal->current_amount, 2),
                'remaining'       => round($remaining, 2),
                'progress_percent'=> $progress,
                'deadline'        => isset($goal->deadline) ? $goal->deadline->format('d M Y') : null,
                'status'          => $progress >= 100 ? 'completed' : ($progress >= 75 ? 'almost_there' : 'in_progress'),
            ];
        });

        $completedGoals = $goals->where('status', 'completed')->count();

        return [
            'goals'           => $goals->values()->toArray(),
            'total_goals'     => $goals->count(),
            'completed_goals' => $completedGoals,
            'active_goals'    => $goals->count() - $completedGoals,
            'currency'        => 'IDR',
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================
    private function assessFinancialHealth(float $savingsRate, float $expense, float $income): string
    {
        if ($income <= 0) {
            return 'Belum ada data pemasukan bulan ini';
        }

        return match (true) {
            $savingsRate >= 20  => 'Sangat Baik — kamu menabung lebih dari 20% pemasukan 🟢',
            $savingsRate >= 10  => 'Baik — tabunganmu di kisaran sehat 🟡',
            $savingsRate >= 0   => 'Perlu Perhatian — pengeluaran hampir setara pemasukan 🟠',
            default             => 'Kritis — pengeluaran melebihi pemasukan bulan ini 🔴',
        };
    }
}