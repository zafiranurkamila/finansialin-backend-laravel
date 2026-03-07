<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BudgetsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $budgets = Budget::query()
            ->where('idUser', $user->idUser)
            ->orderByDesc('periodStart')
            ->get();

        return response()->json($budgets);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'idCategory' => ['nullable', 'integer'],
            'period' => ['nullable', 'in:day,daily,week,weekly,monthly,year,yearly'],
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $categoryId = $request->input('idCategory');
        if ($categoryId !== null) {
            $category = Category::query()->where('idCategory', (int) $categoryId)->first();
            if (!$category || ($category->idUser !== null && $category->idUser !== $user->idUser)) {
                return response()->json(['message' => 'Category not found'], 404);
            }
        }

        $normalizedPeriod = $this->normalizePeriod((string) $request->input('period', 'monthly'));

        $budget = Budget::create([
            'idUser' => $user->idUser,
            'idCategory' => $categoryId,
            'period' => $normalizedPeriod,
            'periodStart' => $this->parseInputDateTime((string) $request->input('periodStart')),
            'periodEnd' => $this->parseInputDateTime((string) $request->input('periodEnd')),
            'amount' => (float) $request->input('amount'),
        ]);

        UserNotification::create([
            'idUser' => $user->idUser,
            'type' => 'BUDGET_CREATED',
            'read' => false,
            'message' => 'Budget baru berhasil dibuat.',
        ]);

        return response()->json($budget, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $budget = Budget::query()->where('idBudget', $id)->first();
        if (!$budget) {
            return response()->json(['message' => 'Budget not found'], 404);
        }

        if ($budget->idUser !== $user->idUser) {
            return response()->json(['error' => 'Not allowed'], 403);
        }

        return response()->json($budget);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $budget = Budget::query()->where('idBudget', $id)->first();
        if (!$budget) {
            return response()->json(['message' => 'Budget not found'], 404);
        }

        if ($budget->idUser !== $user->idUser) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $validator = Validator::make($request->all(), [
            'idCategory' => ['nullable', 'integer'],
            'period' => ['nullable', 'in:day,daily,week,weekly,monthly,year,yearly'],
            'periodStart' => ['nullable', 'date'],
            'periodEnd' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $newPeriod = $request->has('period')
            ? $this->normalizePeriod((string) $request->input('period'))
            : $budget->period;

        $budget->update([
            'idCategory' => $request->has('idCategory') ? $request->input('idCategory') : $budget->idCategory,
            'period' => $newPeriod,
            'periodStart' => $request->has('periodStart') ? $this->parseInputDateTime((string) $request->input('periodStart')) : $budget->periodStart,
            'periodEnd' => $request->has('periodEnd') ? $this->parseInputDateTime((string) $request->input('periodEnd')) : $budget->periodEnd,
            'amount' => $request->input('amount', $budget->amount),
        ]);

        return response()->json($budget->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $budget = Budget::query()->where('idBudget', $id)->first();
        if (!$budget) {
            return response()->json(['message' => 'Budget not found'], 404);
        }

        if ($budget->idUser !== $user->idUser) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $budget->delete();

        UserNotification::create([
            'idUser' => $user->idUser,
            'type' => 'BUDGET_DELETED',
            'read' => false,
            'message' => 'Budget telah dihapus.',
        ]);

        return response()->json(['message' => 'Budget deleted']);
    }

    public function usage(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $budget = Budget::query()->where('idBudget', $id)->first();
        if (!$budget) {
            return response()->json(['message' => 'Budget not found'], 404);
        }

        if ($budget->idUser !== $user->idUser) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $query = Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('type', 'expense')
            ->whereBetween('date', [$budget->periodStart, $budget->periodEnd]);

        if ($budget->idCategory) {
            $query->where('idCategory', $budget->idCategory);
        }

        $used = (float) $query->sum('amount');
        $total = (float) $budget->amount;
        $percent = $total > 0 ? ($used / $total) * 100 : 0;

        if ($total > 0 && $used > $total) {
            UserNotification::create([
                'idUser' => $user->idUser,
                'type' => 'BUDGET_EXCEEDED',
                'read' => false,
                'message' => 'Budget Anda telah melebihi batas.',
            ]);
        }

        return response()->json([
            'used' => $used,
            'total' => $total,
            'percent' => $percent,
        ]);
    }

    public function filter(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $period = $this->normalizePeriod((string) $request->query('period', 'monthly'));
        $date = $request->query('date')
            ? $this->parseInputDateTime((string) $request->query('date'))
            : CarbonImmutable::now('UTC');
        $idCategory = $request->query('idCategory');

        [$start, $end] = $this->periodRange($period, $date);

        $query = Budget::query()
            ->where('idUser', $user->idUser)
            ->where('period', $period)
            ->where(function ($q) use ($start, $end) {
                $q->where('periodStart', '<=', $end)
                    ->where('periodEnd', '>=', $start);
            });

        if ($idCategory !== null) {
            $query->where('idCategory', (int) $idCategory);
        }

        return response()->json($query->orderBy('periodStart')->get());
    }

    public function goals(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $period = $this->normalizePeriod((string) $request->query('period', 'monthly'));
        $txType = (string) $request->query('type', 'expense');
        $date = $request->query('date')
            ? $this->parseInputDateTime((string) $request->query('date'))
            : CarbonImmutable::now('UTC');
        $idCategory = $request->query('idCategory');

        [$start, $end] = $this->periodRange($period, $date);

        $budgetQuery = Budget::query()
            ->where('idUser', $user->idUser)
            ->where('period', $period)
            ->where(function ($q) use ($start, $end) {
                $q->where('periodStart', '<=', $end)
                    ->where('periodEnd', '>=', $start);
            });

        if ($idCategory !== null) {
            $budgetQuery->where('idCategory', (int) $idCategory);
        }

        $budgets = $budgetQuery->get();

        $txQuery = Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('date', '>=', $start)
            ->where('date', '<', $end);

        if (in_array($txType, ['expense', 'income'], true)) {
            $txQuery->where('type', $txType);
        }
        if ($idCategory !== null) {
            $txQuery->where('idCategory', (int) $idCategory);
        }

        $txGroups = $txQuery
            ->selectRaw('idCategory, SUM(amount) as spent')
            ->groupBy('idCategory')
            ->get();

        $spentMap = [];
        foreach ($txGroups as $group) {
            $spentMap[(string) ($group->idCategory ?? 'null')] = (float) $group->spent;
        }

        $budgetMap = [];
        foreach ($budgets as $budget) {
            $key = (string) ($budget->idCategory ?? 'null');
            $budgetMap[$key] = ($budgetMap[$key] ?? 0) + (float) $budget->amount;
        }

        $keys = array_unique(array_merge(array_keys($budgetMap), array_keys($spentMap)));
        $categoryIds = array_values(array_filter(array_map(static function ($k) {
            return $k === 'null' ? null : (int) $k;
        }, $keys), static fn ($v) => $v !== null));

        $categories = Category::query()
            ->whereIn('idCategory', $categoryIds)
            ->get()
            ->keyBy('idCategory');

        $data = [];
        foreach ($keys as $key) {
            $budgetAmount = $budgetMap[$key] ?? 0;
            $spent = $spentMap[$key] ?? 0;
            $remaining = $budgetAmount - $spent;
            $percent = $budgetAmount > 0 ? ($spent / $budgetAmount) * 100 : 0;
            $catId = $key === 'null' ? null : (int) $key;
            $catName = $catId === null ? 'Uncategorized' : ($categories[$catId]->name ?? 'Unknown');

            $data[] = [
                'idCategory' => $catId,
                'name' => $catName,
                'budgetAmount' => $budgetAmount,
                'spent' => $spent,
                'percent' => round($percent, 2),
                'overBudget' => $budgetAmount > 0 ? $spent > $budgetAmount : false,
                'remaining' => round($remaining, 2),
            ];
        }

        $totalBudget = array_sum($budgetMap);
        $totalSpent = array_sum($spentMap);

        return response()->json([
            'period' => [
                'start' => $start,
                'end' => $end,
                'period' => $period,
            ],
            'totals' => [
                'totalBudget' => $totalBudget,
                'totalSpent' => $totalSpent,
                'remaining' => $totalBudget - $totalSpent,
                'percent' => $totalBudget > 0 ? ($totalSpent / $totalBudget) * 100 : 0,
            ],
            'data' => $data,
        ]);
    }

    private function periodRange(string $period, \DateTimeInterface $date): array
    {
        $d = CarbonImmutable::parse($date->format(DATE_ATOM))->utc();

        return match ($period) {
            'daily' => [$d->startOfDay(), $d->startOfDay()->addDay()],
            'weekly' => [$d->startOfWeek(), $d->startOfWeek()->addWeek()],
            'year' => [$d->startOfYear(), $d->startOfYear()->addYear()],
            default => [$d->startOfMonth(), $d->startOfMonth()->addMonth()],
        };
    }

    private function normalizePeriod(string $period): string
    {
        return match ($period) {
            'day', 'daily' => 'daily',
            'week', 'weekly' => 'weekly',
            'year', 'yearly' => 'year',
            default => 'monthly',
        };
    }

    private function parseInputDateTime(string $input): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $input, 'UTC')->startOfDay();
        }

        return CarbonImmutable::parse($input)->utc();
    }
}
