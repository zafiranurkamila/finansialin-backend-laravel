<?php

namespace App\Services;

use App\Models\Resource;
use App\Models\MonthlyCategoryAnalytic;
use App\Models\MonthlyBudgetUsage;
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
        // Query to resources table based on idUser
        return Resource::where('idUser', $userId)
            ->select('source as wallet_name', 'balance')
            ->get();
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
}
