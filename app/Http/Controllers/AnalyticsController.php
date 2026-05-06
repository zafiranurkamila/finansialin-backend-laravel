<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Transaction;
use App\Models\MonthlyCategoryAnalytic;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Get monthly analytics summary (expense & income by category, totals)
     */
    public function monthly(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $month = (int) $request->query('month', date('n'));
        $year = (int) $request->query('year', date('Y'));

        // Validate month and year
        if ($month < 1 || $month > 12 || $year < 1970) {
            return response()->json(['message' => 'Invalid period'], 400);
        }

        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->startOfMonth();
        $end = $start->addMonth();

        // Use the existing view for category analytics if possible
        $categoryAnalytics = MonthlyCategoryAnalytic::where('user_id', $user->idUser)
            ->where('month', $month)
            ->where('year', $year)
            ->get();
            
        // If the view doesn't return anything (maybe it's not populated or doesn't exist), fallback to raw query
        if ($categoryAnalytics->isEmpty()) {
            $categoryAnalytics = Transaction::query()
                ->where('idUser', $user->idUser)
                ->where('date', '>=', $start)
                ->where('date', '<', $end)
                ->join('categories', 'transactions.idCategory', '=', 'categories.idCategory', 'left')
                ->select(
                    'categories.name as category_name',
                    'transactions.idCategory as category_id',
                    'transactions.type',
                    DB::raw('SUM(transactions.amount) as total_amount')
                )
                ->groupBy('transactions.idCategory', 'categories.name', 'transactions.type')
                ->get();
        }

        // Separate into expense and income
        $expenseByCategory = [];
        $incomeByCategory = [];
        $totalExpense = 0;
        $totalIncome = 0;

        foreach ($categoryAnalytics as $stat) {
            $type = $stat->type ?? $stat->transaction_type; // handle different column names from view or raw query
            $amount = (float) ($stat->total_amount ?? $stat->total_spent ?? $stat->amount);
            $catName = $stat->category_name ?? 'Uncategorized';
            $catId = $stat->category_id ?? null;
            
            $item = [
                'idCategory' => $catId,
                'name' => $catName,
                'amount' => round($amount, 2),
            ];

            if ($type === 'expense') {
                $expenseByCategory[] = $item;
                $totalExpense += $amount;
            } else {
                $incomeByCategory[] = $item;
                $totalIncome += $amount;
            }
        }

        // Sort by amount descending
        usort($expenseByCategory, fn($a, $b) => $b['amount'] <=> $a['amount']);
        usort($incomeByCategory, fn($a, $b) => $b['amount'] <=> $a['amount']);

        return response()->json([
            'period' => [
                'month' => $month,
                'year' => $year,
            ],
            'summary' => [
                'totalExpense' => round($totalExpense, 2),
                'totalIncome' => round($totalIncome, 2),
                'net' => round($totalIncome - $totalExpense, 2),
            ],
            'expenseByCategory' => $expenseByCategory,
            'incomeByCategory' => $incomeByCategory,
        ]);
    }
    
    /**
     * Get 6-months trend summary
     */
    public function trend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');
        
        $months = (int) $request->query('months', 6);
        $months = max(1, min(12, $months)); // Limit 1-12 months

        $now = CarbonImmutable::now('UTC');
        
        $trendData = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthDate = $now->subMonths($i)->startOfMonth();
            
            $monthIncome = (float) Transaction::query()
                ->where('idUser', $user->idUser)
                ->where('type', 'income')
                ->whereBetween('date', [$monthDate, $monthDate->endOfMonth()])
                ->sum('amount');
                
            $monthExpense = (float) Transaction::query()
                ->where('idUser', $user->idUser)
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

        return response()->json($trendData);
    }
}
