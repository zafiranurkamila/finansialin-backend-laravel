<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InternalController extends Controller
{
    public function financialContext(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        if (!$userId) {
            return response()->json(['message' => 'Missing user_id parameter'], 400);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $cacheService = app(\App\Services\UserCacheService::class);
        $version = $cacheService->getVersion($user->idUser);
        $cacheKey = "user:{$user->idUser}:financial-context:v{$version}";

        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, \App\Services\UserCacheService::TTL_FINANCIAL_CONTEXT, function () use ($user) {
            $now = CarbonImmutable::now('UTC');
            $month = $now->month;
            $year = $now->year;
            $startOfMonth = $now->startOfMonth();
            $endOfMonth = $now->endOfMonth();

            // 1. Balance
            $income = Transaction::where('idUser', $user->idUser)->where('type', 'income')->sum('amount');
            $expense = Transaction::where('idUser', $user->idUser)->where('type', 'expense')->sum('amount');
            $balance = (float) $income - (float) $expense;

            // 2. 5 Recent transactions
            $recentTransactions = Transaction::with('category')
                ->where('idUser', $user->idUser)
                ->orderByDesc('date')
                ->limit(5)
                ->get();

            // 3. Current month budget status
            $budgets = Budget::with('category')
                ->where('idUser', $user->idUser)
                ->where('periodStart', '<=', $endOfMonth)
                ->where('periodEnd', '>=', $startOfMonth)
                ->get();

            $budgetStatus = $budgets->map(function ($budget) use ($user, $startOfMonth, $endOfMonth) {
                $query = Transaction::query()
                    ->where('idUser', $user->idUser)
                    ->where('type', 'expense')
                    ->where('date', '>=', max($startOfMonth, $budget->periodStart))
                    ->where('date', '<=', min($endOfMonth, $budget->periodEnd));

                if ($budget->idCategory) {
                    $query->where('idCategory', $budget->idCategory);
                }

                $used = (float) $query->sum('amount');

                return [
                    'idBudget' => $budget->idBudget,
                    'category' => $budget->category ? $budget->category->name : 'Uncategorized',
                    'amount' => (float) $budget->amount,
                    'spent' => $used,
                    'remaining' => (float) $budget->amount - $used,
                ];
            });

            // 4. Current month analytics summary
            $monthlyIncome = Transaction::where('idUser', $user->idUser)
                ->where('type', 'income')
                ->whereBetween('date', [$startOfMonth, $endOfMonth])
                ->sum('amount');
                
            $monthlyExpense = Transaction::where('idUser', $user->idUser)
                ->where('type', 'expense')
                ->whereBetween('date', [$startOfMonth, $endOfMonth])
                ->sum('amount');

            $analytics = [
                'period' => "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT),
                'income' => (float) $monthlyIncome,
                'expense' => (float) $monthlyExpense,
                'net' => (float) $monthlyIncome - (float) $monthlyExpense,
            ];

            return [
                'balance' => $balance,
                'recent_transactions' => $recentTransactions,
                'budget_status' => $budgetStatus,
                'analytics' => $analytics,
            ];
        });

        return response()->json($data);
    }
}
