<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Resource;
use App\Models\MonthlyCategoryAnalytic;
use App\Models\MonthlyBudgetUsage;
use App\Models\Transaction;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinancialInsightService
{
    /**
     * Get all wallet balances for a user.
     * 
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getWalletBalances($userId)
    {
        // Query the resources table which stores live wallet balances directly
        return Resource::where('idUser', $userId)
            ->orderBy('createdAt', 'asc')
            ->get()
            ->map(function ($resource) {
                return [
                    'wallet_name' => $resource->source,
                    'balance'     => round((float) $resource->balance, 2),
                ];
            });
    }

    /**
     * Get monthly category analytics for a user.
     * 
     * @param int $userId
     * @param int|null $month
     * @param int|null $year
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMonthlyAnalytics($userId, $month = null, $year = null)
    {
        $now = Carbon::now();
        $month = $month ?? $now->month;
        $year = $year ?? $now->year;

        return MonthlyCategoryAnalytic::where('user_id', $userId)
            ->where('month', $month)
            ->where('year', $year)
            ->get();
    }

    /**
     * Get monthly budget usage status for a user.
     * 
     * @param int $userId
     * @param int|null $month
     * @param int|null $year
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getBudgetStatus($userId, $month = null, $year = null)
    {
        $now = Carbon::now();
        $month = $month ?? $now->month;
        $year = $year ?? $now->year;

        // Query to MonthlyBudgetUsage view
        return MonthlyBudgetUsage::where('user_id', $userId)
            ->where('month', $month)
            ->where('year', $year)
            ->get();
    }

    /**
     * Get recent transactions for a user.
     * 
     * @param int $userId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRecentTransactions($userId, $limit = 5)
    {
        return Transaction::with('category:idCategory,name')
            ->where('idUser', $userId)
            ->orderByDesc('date')
            ->limit($limit)
            ->get()
            ->map(function ($tx) {
                return [
                    'idTransaction' => $tx->idTransaction,
                    'amount' => $tx->amount,
                    'type' => $tx->type,
                    'category' => $tx->category ? $tx->category->name : 'Uncategorized',
                    'date' => $tx->date->format('Y-m-d H:i:s'),
                    'description' => $tx->description ?? '',
                    'source' => $tx->source ?? 'Manual'
                ];
            });
    }

    /**
     * Get monthly summary (income/expense totals + top categories).
     */
    public function getMonthlySummary(int $userId, ?int $month = null, ?int $year = null): array
    {
        $now = CarbonImmutable::now('UTC');
        $month = $month ?? $now->month;
        $year = $year ?? $now->year;

        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->startOfMonth();
        $end = $start->addMonth();

        $incomeTotal = (float) Transaction::query()
            ->where('idUser', $userId)
            ->where('type', 'income')
            ->whereBetween('date', [$start, $end])
            ->sum('amount');

        $expenseTotal = (float) Transaction::query()
            ->where('idUser', $userId)
            ->where('type', 'expense')
            ->whereBetween('date', [$start, $end])
            ->sum('amount');

        $expenseByCategory = $this->getCategoryBreakdown($userId, $start, $end, 'expense');
        $incomeByCategory = $this->getCategoryBreakdown($userId, $start, $end, 'income');

        $topExpenseCategory = $expenseByCategory[0]['name'] ?? null;
        $topExpenseAmount = $expenseByCategory[0]['amount'] ?? 0;

        return [
            'period' => [
                'month' => $month,
                'year' => $year,
            ],
            'summary' => [
                'totalIncome' => round($incomeTotal, 2),
                'totalExpense' => round($expenseTotal, 2),
                'net' => round($incomeTotal - $expenseTotal, 2),
            ],
            'topExpenseCategory' => $topExpenseCategory,
            'topExpenseAmount' => round($topExpenseAmount, 2),
            'expenseByCategory' => $expenseByCategory,
            'incomeByCategory' => $incomeByCategory,
        ];
    }

    /**
     * Get all-time summary (income/expense totals + top categories).
     */
    public function getAllTimeSummary(int $userId): array
    {
        $incomeTotal = (float) Transaction::query()
            ->where('idUser', $userId)
            ->where('type', 'income')
            ->sum('amount');

        $expenseTotal = (float) Transaction::query()
            ->where('idUser', $userId)
            ->where('type', 'expense')
            ->sum('amount');

        $expenseByCategory = $this->getCategoryBreakdown($userId, null, null, 'expense');
        $incomeByCategory = $this->getCategoryBreakdown($userId, null, null, 'income');

        $topExpenseCategory = $expenseByCategory[0]['name'] ?? null;
        $topExpenseAmount = $expenseByCategory[0]['amount'] ?? 0;

        return [
            'summary' => [
                'totalIncome' => round($incomeTotal, 2),
                'totalExpense' => round($expenseTotal, 2),
                'net' => round($incomeTotal - $expenseTotal, 2),
            ],
            'topExpenseCategory' => $topExpenseCategory,
            'topExpenseAmount' => round($topExpenseAmount, 2),
            'expenseByCategory' => $expenseByCategory,
            'incomeByCategory' => $incomeByCategory,
        ];
    }

    /**
     * Get spending trend for the last N months.
     */
    public function getSpendingTrend(int $userId, int $months = 3): array
    {
        $months = max(1, min(12, $months));
        $now = CarbonImmutable::now('UTC');
        $trendData = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthDate = $now->subMonths($i)->startOfMonth();

            $monthIncome = (float) Transaction::query()
                ->where('idUser', $userId)
                ->where('type', 'income')
                ->whereBetween('date', [$monthDate, $monthDate->endOfMonth()])
                ->sum('amount');

            $monthExpense = (float) Transaction::query()
                ->where('idUser', $userId)
                ->where('type', 'expense')
                ->whereBetween('date', [$monthDate, $monthDate->endOfMonth()])
                ->sum('amount');

            $trendData[] = [
                'month_label' => $monthDate->format('M Y'),
                'month' => $monthDate->month,
                'year' => $monthDate->year,
                'income' => round($monthIncome, 2),
                'expense' => round($monthExpense, 2),
                'net' => round($monthIncome - $monthExpense, 2),
            ];
        }

        return $trendData;
    }

    /**
     * Get financial profile (balance + totals) for a user.
     */
    public function getUserFinancialProfile(int $userId): array
    {
        $totalBalance = (float) Resource::query()
            ->where('idUser', $userId)
            ->sum('balance');

        $incomeTotal = (float) Transaction::query()
            ->where('idUser', $userId)
            ->where('type', 'income')
            ->sum('amount');

        $expenseTotal = (float) Transaction::query()
            ->where('idUser', $userId)
            ->where('type', 'expense')
            ->sum('amount');

        return [
            'totalBalance' => round($totalBalance, 2),
            'totalIncome' => round($incomeTotal, 2),
            'totalExpense' => round($expenseTotal, 2),
            'net' => round($incomeTotal - $expenseTotal, 2),
        ];
    }

    /**
     * Get savings goals (using current budgets as proxy).
     */
    public function getSavingsGoals(int $userId): array
    {
        $budgets = Budget::query()
            ->with('category')
            ->where('idUser', $userId)
            ->orderByDesc('periodStart')
            ->limit(10)
            ->get();

        return $budgets->map(function (Budget $budget) use ($userId) {
            $spent = (float) Transaction::query()
                ->where('idUser', $userId)
                ->where('type', 'expense')
                ->whereBetween('date', [$budget->periodStart, $budget->periodEnd])
                ->when($budget->idCategory, fn ($q) => $q->where('idCategory', $budget->idCategory))
                ->sum('amount');

            return [
                'idBudget' => $budget->idBudget,
                'category' => $budget->category?->name ?? 'General',
                'period' => $budget->period,
                'periodStart' => $budget->periodStart?->format('Y-m-d'),
                'periodEnd' => $budget->periodEnd?->format('Y-m-d'),
                'amount' => (float) $budget->amount,
                'spent' => round($spent, 2),
                'remaining' => round(max(0, (float) $budget->amount - $spent), 2),
            ];
        })->values()->all();
    }

    private function getCategoryBreakdown(int $userId, ?CarbonImmutable $start, ?CarbonImmutable $end, ?string $type = null): array
    {
        $query = Transaction::query()
            ->where('transactions.idUser', $userId)
            ->leftJoin('categories', 'transactions.idCategory', '=', 'categories.idCategory')
            ->select(
                'categories.idCategory as idCategory',
                'categories.name as name',
                'transactions.type as type',
                DB::raw('SUM(transactions.amount) as total_amount')
            )
            ->groupBy('categories.idCategory', 'categories.name', 'transactions.type');

        if ($type) {
            $query->where('transactions.type', $type);
        }

        if ($start && $end) {
            $query->whereBetween('transactions.date', [$start, $end]);
        }

        $rows = $query->get();
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'idCategory' => $row->idCategory,
                'name' => $row->name ?? 'Uncategorized',
                'amount' => round((float) $row->total_amount, 2),
            ];
        }

        usort($result, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return $result;
    }
}
