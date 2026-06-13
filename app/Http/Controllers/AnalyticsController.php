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

        $cacheService = app(\App\Services\UserCacheService::class);
        $version = $cacheService->getVersion($user->idUser);
        $cacheKey = "user:{$user->idUser}:analytics:v{$version}:{$year}-{$month}";

        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, \App\Services\UserCacheService::TTL_ANALYTICS, function () use ($user, $month, $year) {
            $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->startOfMonth();
            $end = $start->addMonth();

            $categoryAnalytics = MonthlyCategoryAnalytic::where('user_id', $user->idUser)
                ->where('month', $month)
                ->where('year', $year)
                ->get();
                
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

            $expenseByCategory = [];
            $incomeByCategory = [];
            $totalExpense = 0;
            $totalIncome = 0;

            foreach ($categoryAnalytics as $stat) {
                $type = $stat->type ?? $stat->transaction_type; 
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

            usort($expenseByCategory, fn($a, $b) => $b['amount'] <=> $a['amount']);
            usort($incomeByCategory, fn($a, $b) => $b['amount'] <=> $a['amount']);

            return [
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
            ];
        });

        return response()->json($data);
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
        
        $cacheService = app(\App\Services\UserCacheService::class);
        $version = $cacheService->getVersion($user->idUser);
        $cacheKey = "user:{$user->idUser}:trend:v{$version}:{$months}";

        $trendData = \Illuminate\Support\Facades\Cache::remember($cacheKey, \App\Services\UserCacheService::TTL_TREND, function () use ($user, $months, $now) {
            $data = [];
            
            $startDate = $now->subMonths($months - 1)->startOfMonth();
            $endDate = $now->endOfMonth();

            $stats = Transaction::query()
                ->where('idUser', $user->idUser)
                ->where('date', '>=', $startDate)
                ->where('date', '<=', $endDate)
                ->selectRaw('EXTRACT(YEAR FROM "date") as year, EXTRACT(MONTH FROM "date") as month, type, SUM(amount) as total')
                ->groupByRaw('EXTRACT(YEAR FROM "date"), EXTRACT(MONTH FROM "date"), type')
                ->get();

            $statsMap = [];
            foreach ($stats as $stat) {
                $key = (int) $stat->year . '-' . (int) $stat->month;
                if (!isset($statsMap[$key])) {
                    $statsMap[$key] = ['income' => 0.0, 'expense' => 0.0];
                }
                $statsMap[$key][$stat->type] = (float) $stat->total;
            }
            
            for ($i = $months - 1; $i >= 0; $i--) {
                $monthDate = $now->subMonths($i)->startOfMonth();
                $key = $monthDate->year . '-' . $monthDate->month;
                
                $monthIncome = $statsMap[$key]['income'] ?? 0.0;
                $monthExpense = $statsMap[$key]['expense'] ?? 0.0;
                
                $data[] = [
                    'month_label' => $monthDate->format('M Y'),
                    'month' => $monthDate->month,
                    'year' => $monthDate->year,
                    'income' => round($monthIncome, 2),
                    'expense' => round($monthExpense, 2),
                    'net' => round($monthIncome - $monthExpense, 2),
                ];
            }
            
            return $data;
        });

        return response()->json($trendData);
    }
}
