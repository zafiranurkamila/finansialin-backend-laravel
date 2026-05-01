<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Resource;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ResourceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TransactionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min(100, $perPage)); // Limit between 1 and 100

        $transactions = Transaction::query()
            ->where('idUser', $user->idUser)
            ->with(['category:idCategory,name', 'resource:idResource,source'])
            ->orderByDesc('date')
            ->paginate($perPage);

        return response()->json($transactions);
    }

    public function byMonth(Request $request, int $year, int $month): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        if ($month < 1 || $month > 12 || $year < 1970) {
            return response()->json(['message' => 'Invalid period'], 400);
        }

        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->startOfMonth();
        $end = $start->addMonth();

        $transactions = Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('date', '>=', $start)
            ->where('date', '<', $end)
            ->with(['category:idCategory,name', 'resource:idResource,source'])
            ->orderByDesc('date')
            ->get();

        return response()->json($transactions);
    }

    public function search(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $query = Transaction::query()->where('idUser', $user->idUser);

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('description', 'like', '%' . $q . '%')
                    ->orWhereHas('resource', function ($resourceQuery) use ($q) {
                        $resourceQuery->where('source', 'like', '%' . $q . '%');
                    });
            });
        }

        $type = (string) $request->query('type', '');
        if (in_array($type, ['income', 'expense'], true)) {
            $query->where('type', $type);
        }

        if ($request->filled('idCategory')) {
            $query->where('idCategory', (int) $request->query('idCategory'));
        }

        $source = trim((string) $request->query('source', ''));
        if ($source !== '') {
            $query->whereHas('resource', function ($resourceQuery) use ($source) {
                $resourceQuery->where('source', 'like', '%' . $source . '%');
            });
        }

        if ($request->filled('minAmount')) {
            $query->where('amount', '>=', (float) $request->query('minAmount'));
        }

        if ($request->filled('maxAmount')) {
            $query->where('amount', '<=', (float) $request->query('maxAmount'));
        }

        if ($request->filled('dateFrom')) {
            $query->where('date', '>=', $this->parseInputDateTime((string) $request->query('dateFrom')));
        }

        if ($request->filled('dateTo')) {
            $query->where('date', '<=', $this->parseInputDateTime((string) $request->query('dateTo')));
        }

        $sortBy = (string) $request->query('sortBy', 'date');
        $allowedSort = ['date', 'amount', 'createdAt', 'updatedAt'];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'date';
        }

        $sortOrder = strtolower((string) $request->query('sortOrder', 'desc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min(100, $perPage)); // Limit between 1 and 100

        $transactions = $query
            ->with(['category:idCategory,name', 'resource:idResource,source'])
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

        return response()->json($transactions);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'idCategory' => ['nullable', 'integer'],
            'idResource' => ['required', 'integer'],
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $resource = $this->resolveResource((int) $user->idUser, (int) $request->input('idResource'));
        if (!$resource) {
            return response()->json([
                'message' => 'Resource not found',
            ], 422);
        }

        $category = $this->resolveCategory($user, $request->input('idCategory'));
        if ($request->has('idCategory')) {
            if ($category === false) {
                return response()->json(['message' => 'Not allowed'], 403);
            }
            if ($category === null) {
                return response()->json(['message' => 'Category not found'], 404);
            }
        }

        $txDate = $request->input('date')
            ? $this->parseInputDateTime((string) $request->input('date'))
            : CarbonImmutable::now('UTC');

        $txType = $request->string('type')->toString();
        $txAmount = (float) $request->input('amount');
        $allowedIncomeResources = ['mbanking', 'emoney'];
        if ($txType === 'income' && !in_array(strtolower((string) $resource->source), $allowedIncomeResources, true)) {
            return response()->json([
                'message' => 'Income transactions can only be added to mbanking or emoney resources',
                'allowedResources' => $allowedIncomeResources,
            ], 422);
        }

        if ($txType === 'expense' && $txAmount > (float) $resource->balance) {
                return response()->json([
                    'message' => 'Insufficient balance for selected resource',
                    'availableSourceBalance' => round((float) $resource->balance, 2),
                    'source' => (string) $resource->source,
                ], 422);
        }

        $transaction = Transaction::create([
            'idUser' => $user->idUser,
            'idCategory' => $category?->idCategory,
            'idResource' => $resource->idResource,
            'type' => $txType,
            'amount' => $txAmount,
            'description' => $request->input('description'),
            'date' => $txDate,
        ]);

        if ($txType === 'income') {
            ResourceService::addIncomeToResource($resource->idResource, $txAmount);
        } elseif ($txType === 'expense') {
            ResourceService::withdrawFromResource($resource->idResource, $txAmount);
        }

        $this->notifyTransaction($user->idUser, $transaction, true);
        $this->checkBudgetWarning($user, $transaction);

        return response()->json($transaction->fresh(['resource:idResource,source']), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $transaction = Transaction::query()->where('idTransaction', $id)->first();
        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($transaction->idUser !== $user->idUser) {
            return response()->json(['error' => 'Not allowed'], 403);
        }

        return response()->json($transaction->load(['category:idCategory,name', 'resource:idResource,source']));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $transaction = Transaction::query()->where('idTransaction', $id)->first();
        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($transaction->idUser !== $user->idUser) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        $validator = Validator::make($request->all(), [
            'idCategory' => ['nullable', 'integer'],
            'idResource' => ['nullable', 'integer'],
            'type' => ['nullable', 'in:income,expense'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $resource = null;
        if ($request->filled('idResource')) {
            $resource = $this->resolveResource((int) $user->idUser, (int) $request->input('idResource'));
            if (!$resource) {
                return response()->json([
                    'message' => 'Resource not found',
                ], 422);
            }
        }

        $newCategoryId = $request->has('idCategory') ? $request->input('idCategory') : $transaction->idCategory;
        $category = $this->resolveCategory($user, $newCategoryId);
        if ($newCategoryId !== null) {
            if ($category === false) {
                return response()->json(['message' => 'Not allowed'], 403);
            }
            if ($category === null) {
                return response()->json(['message' => 'Category not found'], 404);
            }
        }

        $nextType = $request->input('type', $transaction->type);
        $nextAmount = (float) $request->input('amount', $transaction->amount);

        $newResId = $request->has('idResource')
            ? ($request->filled('idResource') ? (int) $request->input('idResource') : null)
            : ($transaction->idResource ? (int) $transaction->idResource : null);

        if ($newResId !== null) {
            $resource = $this->resolveResource((int) $user->idUser, $newResId);
            if (!$resource) {
                return response()->json([
                    'message' => 'Resource not found',
                ], 422);
            }

            if ($nextType === 'income') {
                $allowedIncomeResources = ['mbanking', 'emoney'];
                if (!in_array(strtolower((string) $resource->source), $allowedIncomeResources, true)) {
                    return response()->json([
                        'message' => 'Income transactions can only be added to mbanking or emoney resources',
                        'allowedResources' => $allowedIncomeResources,
                    ], 422);
                }
            }

        }

        if ($nextType === 'expense' && $resource) {
            $sourceBalance = $this->availableResourceBalanceForUpdate($resource, $transaction);

            if ($nextAmount > $sourceBalance) {
                return response()->json([
                    'message' => 'Insufficient balance for selected resource',
                    'availableSourceBalance' => round($sourceBalance, 2),
                    'source' => (string) $resource->source,
                ], 422);
            }
        }

        $oldResId = $transaction->idResource;
        $oldAmount = $transaction->amount;
        $oldType = $transaction->type;
        $newAmount = $nextAmount;
        $newType = $nextType;

        if ($oldResId) {
            if ($oldType === 'income') {
                ResourceService::withdrawFromResource($oldResId, $oldAmount);
            } elseif ($oldType === 'expense') {
                ResourceService::addIncomeToResource($oldResId, $oldAmount);
            }
        }

        $transaction->update([
            'idCategory' => $newCategoryId,
            'idResource' => $newResId,
            'type' => $nextType,
            'amount' => $nextAmount,
            'description' => $request->input('description', $transaction->description),
            'date' => $request->has('date')
                ? $this->parseInputDateTime((string) $request->input('date'))
                : $transaction->date,
        ]);

        if ($newResId) {
            if ($newType === 'income') {
                ResourceService::addIncomeToResource($newResId, $newAmount);
            } elseif ($newType === 'expense') {
                ResourceService::withdrawFromResource($newResId, $newAmount);
            }
        }

        return response()->json($transaction->fresh(['resource:idResource,source']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $transaction = Transaction::query()->where('idTransaction', $id)->first();
        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($transaction->idUser !== $user->idUser) {
            return response()->json(['message' => 'Not allowed'], 403);
        }

        if ($transaction->idResource) {
            if ($transaction->type === 'income') {
                ResourceService::withdrawFromResource($transaction->idResource, $transaction->amount);
            } elseif ($transaction->type === 'expense') {
                ResourceService::addIncomeToResource($transaction->idResource, $transaction->amount);
            }
        }

        if ($transaction->receiptImagePath) {
            Storage::disk('public')->delete($transaction->receiptImagePath);
        }

        $this->notifyTransaction($user->idUser, $transaction, false);
        $transaction->delete();

        return response()->json(['message' => 'Transaction deleted']);
    }

    private function resolveCategory(User $user, mixed $idCategory): Category|false|null
    {
        if ($idCategory === null || $idCategory === '') {
            return null;
        }

        $category = Category::query()->where('idCategory', (int) $idCategory)->first();

        if (!$category) {
            return null;
        }

        if ($category->idUser !== null && $category->idUser !== $user->idUser) {
            return false;
        }

        return $category;
    }

    private function notifyTransaction(int $userId, Transaction $tx, bool $isCreate): void
    {
        $label = $tx->type === 'income' ? 'Pemasukan' : 'Pengeluaran';
        $action = $isCreate ? 'ditambahkan' : 'dihapus';
        $formatted = number_format((float) $tx->amount, 0, ',', '.');

        UserNotification::create([
            'idUser' => $userId,
            'type' => $isCreate ? 'TRANSACTION_CREATED' : 'TRANSACTION_DELETED',
            'read' => false,
            'message' => $label . ' sebesar Rp' . $formatted . ' telah ' . $action . '.',
        ]);
    }

    private function checkBudgetWarning(User $user, Transaction $tx): void
    {
        if ($tx->type !== 'expense' || !$tx->idCategory) {
            return;
        }

        $budgets = Budget::query()
            ->where('idUser', $user->idUser)
            ->where('idCategory', $tx->idCategory)
            ->where('periodStart', '<=', $tx->date)
            ->where('periodEnd', '>=', $tx->date)
            ->get();

        foreach ($budgets as $budget) {
            $spent = (float) Transaction::query()
                ->where('idUser', $user->idUser)
                ->where('idCategory', $tx->idCategory)
                ->where('type', 'expense')
                ->whereBetween('date', [$budget->periodStart, $budget->periodEnd])
                ->sum('amount');

            $limit = (float) $budget->amount;
            if ($limit <= 0) {
                continue;
            }

            $percent = ($spent / $limit) * 100;
            $categoryName = $tx->category?->name ?? 'Unknown';

            if ($percent >= 100) {
                UserNotification::create([
                    'idUser' => $user->idUser,
                    'type' => 'BUDGET_EXCEEDED',
                    'read' => false,
                    'message' => 'Budget ' . $categoryName . ' telah melebihi batas.',
                ]);
            } elseif ($percent >= 80) {
                UserNotification::create([
                    'idUser' => $user->idUser,
                    'type' => 'BUDGET_WARNING',
                    'read' => false,
                    'message' => 'Budget ' . $categoryName . ' sudah mencapai ' . round($percent) . '%.',
                ]);
            }
        }
    }

    private function parseInputDateTime(string $input): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $input, 'UTC')->startOfDay();
        }

        return CarbonImmutable::parse($input)->utc();
    }

    private function resolveResource(int $userId, int $resourceId): ?Resource
    {
        return Resource::query()
            ->where('idUser', $userId)
            ->where('idResource', $resourceId)
            ->first();
    }

    private function availableResourceBalanceForUpdate(Resource $resource, Transaction $existingTransaction): float
    {
        $balance = (float) $resource->balance;

        if ((int) $existingTransaction->idResource === (int) $resource->idResource) {
            if ($existingTransaction->type === 'income') {
                $balance -= (float) $existingTransaction->amount;
            }

            if ($existingTransaction->type === 'expense') {
                $balance += (float) $existingTransaction->amount;
            }
        }

        return round($balance, 2);
    }
}
