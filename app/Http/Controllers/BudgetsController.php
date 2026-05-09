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
            ->with('category')
            ->where('idUser', $user->idUser)
            ->orderByDesc('periodStart')
            ->get();

        $result = $budgets->map(function (Budget $budget) use ($user) {
            $query = Transaction::query()
                ->where('idUser', $user->idUser)
                ->where('type', 'expense')
                ->whereBetween('date', [$budget->periodStart, $budget->periodEnd]);

            if ($budget->idCategory) {
                $query->where('idCategory', $budget->idCategory);
            }

            $used = (float) $query->sum('amount');

            $arr = $budget->toArray();
            $arr['spent'] = $used;
            $arr['percent'] = $budget->amount > 0 ? ($used / $budget->amount) * 100 : 0;

            return $arr;
        });

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'idCategory' => ['nullable', 'integer'],
            'period' => ['nullable', 'in:day,daily,week,weekly,monthly,year,yearly,custom'],
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
            'amount' => $request->input('amount'),
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
            'period' => ['nullable', 'in:day,daily,week,weekly,monthly,year,yearly,custom'],
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
            ->selectRaw('"idCategory", SUM(amount) as spent')
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
            $category = $catId !== null ? $categories->get($catId) : null;
            $catName = $catId === null ? 'Uncategorized' : ($category?->name ?? 'Unknown');

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

    public function incomeSplit(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'period' => ['nullable', 'in:day,daily,week,weekly,monthly,year,yearly'],
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
            'apply' => ['nullable', 'boolean'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.idCategory' => ['nullable', 'integer'],
            'allocations.*.percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $period = $this->normalizePeriod((string) $request->input('period', 'monthly'));
        $periodStart = $this->parseInputDateTime((string) $request->input('periodStart'));
        $periodEnd = $this->parseInputDateTime((string) $request->input('periodEnd'));
        $apply = (bool) $request->boolean('apply');

        $allocations = $request->input('allocations', []);
        $totalPercent = array_reduce($allocations, static function (float $carry, array $item): float {
            return $carry + (float) ($item['percent'] ?? 0);
        }, 0.0);

        if ($totalPercent > 100.0) {
            return response()->json([
                'message' => 'Total percentage cannot exceed 100',
            ], 422);
        }

        $categoryIds = [];
        foreach ($allocations as $allocation) {
            if (array_key_exists('idCategory', $allocation) && $allocation['idCategory'] !== null) {
                $categoryIds[] = (int) $allocation['idCategory'];
            }
        }

        if ($categoryIds !== []) {
            $allowedCategories = Category::query()
                ->whereIn('idCategory', $categoryIds)
                ->where(function ($query) use ($user) {
                    $query->whereNull('idUser')->orWhere('idUser', $user->idUser);
                })
                ->pluck('idCategory')
                ->all();

            $invalid = array_diff($categoryIds, array_map('intval', $allowedCategories));
            if ($invalid !== []) {
                return response()->json([
                    'message' => 'Category not found',
                ], 404);
            }
        }

        $totalIncome = (float) Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('type', 'income')
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->sum('amount');

        $rows = [];
        $createdBudgets = [];

        foreach ($allocations as $allocation) {
            $percent = (float) $allocation['percent'];
            $amount = round(($totalIncome * $percent) / 100, 2);
            $idCategory = array_key_exists('idCategory', $allocation) ? $allocation['idCategory'] : null;

            $rows[] = [
                'idCategory' => $idCategory !== null ? (int) $idCategory : null,
                'percent' => round($percent, 2),
                'amount' => $amount,
            ];

            if ($apply) {
                $createdBudgets[] = Budget::query()->create([
                    'idUser' => $user->idUser,
                    'idCategory' => $idCategory !== null ? (int) $idCategory : null,
                    'period' => $period,
                    'periodStart' => $periodStart,
                    'periodEnd' => $periodEnd,
                    'amount' => $amount,
                ]);
            }
        }

        if ($apply && $createdBudgets !== []) {
            UserNotification::create([
                'idUser' => $user->idUser,
                'type' => 'BUDGET_CREATED',
                'read' => false,
                'message' => 'Budget income split berhasil dibuat.',
            ]);
        }

        return response()->json([
            'period' => [
                'period' => $period,
                'periodStart' => $periodStart,
                'periodEnd' => $periodEnd,
            ],
            'summary' => [
                'totalIncome' => round($totalIncome, 2),
                'totalPercent' => round($totalPercent, 2),
                'unallocatedPercent' => round(100 - $totalPercent, 2),
                'apply' => $apply,
            ],
            'allocations' => $rows,
            'createdBudgets' => $createdBudgets,
        ]);
    }

    private function periodRange(string $period, \DateTimeInterface $date): array
    {
        $d = CarbonImmutable::parse($date->format(DATE_ATOM))->utc();

        return match ($period) {
            'daily' => [$d->startOfDay(), $d->endOfDay()],
            'weekly' => [$d->startOfWeek(), $d->endOfWeek()],
            'year' => [$d->startOfYear(), $d->endOfYear()],
            default => [$d->startOfMonth(), $d->endOfMonth()],
        };
    }

    private function normalizePeriod(string $period): string
    {
        return match ($period) {
            'day', 'daily' => 'daily',
            'week', 'weekly' => 'weekly',
            'year', 'yearly' => 'year',
            'custom' => 'custom',
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

    private function riskLevel(float $utilizationPercent): string
    {
        if ($utilizationPercent >= 100) {
            return 'high';
        }

        if ($utilizationPercent >= 85) {
            return 'medium';
        }

        return 'low';
    }
}
