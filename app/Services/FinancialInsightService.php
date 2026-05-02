<?php

namespace App\Services;

use App\Models\Resource;
use App\Models\MonthlyCategoryAnalytic;
use App\Models\MonthlyBudgetUsage;
use App\Models\Transaction;
use Carbon\Carbon;

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
}
