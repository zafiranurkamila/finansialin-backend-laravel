<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\FundingSource;
use App\Models\Resource;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ResourceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            ->with('category:idCategory,name')
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
            ->with('category:idCategory,name')
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
                    ->orWhere('source', 'like', '%' . $q . '%');
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
            $query->where('source', 'like', '%' . $source . '%');
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
            ->with('category:idCategory,name')
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
            'idFundingSource' => ['nullable', 'integer'],
            'idResource' => ['nullable', 'integer'],
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'date' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'receiptImage' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        if ($request->filled('idResource')) {
            $resource = $this->resolveResource((int) $user->idUser, (int) $request->input('idResource'));
            if (!$resource) {
                return response()->json([
                    'message' => 'Resource not found',
                ], 422);
            }
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
        $sourceName = trim((string) $request->input('source', ''));
        $fundingSource = null;

        if ($request->filled('idFundingSource')) {
            $fundingSource = $this->resolveFundingSourceById((int) $user->idUser, (int) $request->input('idFundingSource'));
            if (!$fundingSource) {
                return response()->json([
                    'message' => 'Funding source not found',
                ], 422);
            }

            $sourceName = $fundingSource->name;
        }

        if ($txType === 'expense') {
            $balance = $this->currentBalance((int) $user->idUser);
            if ($txAmount > $balance) {
                return response()->json([
                    'message' => 'Insufficient balance for this expense',
                    'currentBalance' => round($balance, 2),
                ], 422);
            }
        }

        if ($sourceName !== '' && !$fundingSource) {
            $fundingSource = $this->resolveFundingSource((int) $user->idUser, $sourceName);
            if (!$fundingSource) {
                return response()->json([
                    'message' => 'Funding source not found',
                ], 422);
            }

            $sourceName = $fundingSource->name;
        }

        if ($sourceName !== '') {
            if ($txType === 'expense') {
                $sourceBalance = $this->sourceBalance((int) $user->idUser, $fundingSource->name, (float) $fundingSource->initialBalance);
                if ($txAmount > $sourceBalance) {
                    return response()->json([
                        'message' => 'Insufficient balance for selected funding source',
                        'availableSourceBalance' => round($sourceBalance, 2),
                        'source' => $fundingSource->name,
                    ], 422);
                }
            }

        }

        $receiptImagePath = null;
        if ($request->hasFile('receiptImage')) {
            $receiptImagePath = $this->storeReceiptImage($request->file('receiptImage'), $user->idUser);
        }

        $transaction = Transaction::create([
            'idUser' => $user->idUser,
            'idCategory' => $category?->idCategory,
            'idResource' => $request->input('idResource'),
            'type' => $txType,
            'amount' => $txAmount,
            'description' => $request->input('description'),
            'date' => $txDate,
            'source' => $sourceName !== '' ? $sourceName : null,
            'receiptImagePath' => $receiptImagePath,
        ]);

        // Update resource balance jika idResource ada
        // If a resource was explicitly provided or the sourceName maps to a Resource,
        // apply balance changes to that resource. This allows frontends to send
        // a `source` like "mbanking", "emoney" or "cash" and have the
        // corresponding resource balance update automatically.
        $appliedResourceId = null;
        if (isset($resource) && $resource) {
            $appliedResourceId = $resource->idResource;
        } else {
            // try to resolve resource by source name when idResource not provided
            if (empty($appliedResourceId) && $sourceName !== '') {
                $resolved = $this->resolveResourceBySourceName((int) $user->idUser, $sourceName);
                if ($resolved) {
                    $appliedResourceId = $resolved->idResource;
                    // also set idResource on transaction for consistency
                    $transaction->update(['idResource' => $appliedResourceId]);
                }
            }
        }

        if ($appliedResourceId) {
            if ($txType === 'income') {
                ResourceService::addIncomeToResource($appliedResourceId, $txAmount);
            } elseif ($txType === 'expense') {
                ResourceService::withdrawFromResource($appliedResourceId, $txAmount);
            }
        }

        $this->notifyTransaction($user->idUser, $transaction, true);
        $this->checkBudgetWarning($user, $transaction);

        return response()->json($transaction, 201);
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

        return response()->json($transaction);
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
            'idFundingSource' => ['nullable', 'integer'],
            'idResource' => ['nullable', 'integer'],
            'type' => ['nullable', 'in:income,expense'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'date' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'receiptImage' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'removeReceiptImage' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

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

        $receiptImagePath = $transaction->receiptImagePath;
        $removeReceiptImage = $request->boolean('removeReceiptImage');

        $nextType = $request->input('type', $transaction->type);
        $nextAmount = (float) $request->input('amount', $transaction->amount);
        $nextSourceName = $request->has('source')
            ? trim((string) $request->input('source', ''))
            : trim((string) ($transaction->source ?? ''));

        $fundingSource = null;
        if ($request->filled('idFundingSource')) {
            $fundingSource = $this->resolveFundingSourceById((int) $user->idUser, (int) $request->input('idFundingSource'));
            if (!$fundingSource) {
                return response()->json([
                    'message' => 'Funding source not found',
                ], 422);
            }
            $nextSourceName = $fundingSource->name;
        } elseif ($request->has('idFundingSource')) {
            $nextSourceName = '';
        }

        if ($nextSourceName !== '' && !$fundingSource) {
            $fundingSource = $this->resolveFundingSource((int) $user->idUser, $nextSourceName);
            if (!$fundingSource) {
                return response()->json([
                    'message' => 'Funding source not found',
                ], 422);
            }
            $nextSourceName = $fundingSource->name;
        }

        if ($nextType === 'expense') {
            $balance = $this->currentBalance((int) $user->idUser, (int) $transaction->idTransaction);
            if ($nextAmount > $balance) {
                return response()->json([
                    'message' => 'Insufficient balance for this expense',
                    'currentBalance' => round($balance, 2),
                ], 422);
            }

            if ($fundingSource) {
                $sourceBalance = $this->sourceBalance(
                    (int) $user->idUser,
                    $fundingSource->name,
                    (float) $fundingSource->initialBalance,
                    (int) $transaction->idTransaction
                );

                if ($nextAmount > $sourceBalance) {
                    return response()->json([
                        'message' => 'Insufficient balance for selected funding source',
                        'availableSourceBalance' => round($sourceBalance, 2),
                        'source' => $fundingSource->name,
                    ], 422);
                }
            }
        }

        if ($removeReceiptImage && $receiptImagePath) {
            Storage::disk('public')->delete($receiptImagePath);
            $receiptImagePath = null;
        }

        if ($request->hasFile('receiptImage')) {
            if ($receiptImagePath) {
                Storage::disk('public')->delete($receiptImagePath);
            }
            $receiptImagePath = $this->storeReceiptImage($request->file('receiptImage'), $user->idUser);
        }

        // Handle resource balance adjustment
        $oldResId = $transaction->idResource;
        $oldAmount = $transaction->amount;
        $oldType = $transaction->type;
        $newResId = $request->filled('idResource') ? $request->input('idResource') : $oldResId;
        $newAmount = $nextAmount;
        $newType = $nextType;

        // Undo old resource transaction
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
            'source' => $nextSourceName !== '' ? $nextSourceName : null,
            'receiptImagePath' => $receiptImagePath,
        ]);

        // Apply new resource transaction
        if ($newResId) {
            if ($newType === 'income') {
                ResourceService::addIncomeToResource($newResId, $newAmount);
            } elseif ($newType === 'expense') {
                ResourceService::withdrawFromResource($newResId, $newAmount);
            }
        }

        return response()->json($transaction->fresh());
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

        // Undo resource balance
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

    private function storeReceiptImage(UploadedFile $file, int $userId): string
    {
        return $file->store('receipts/' . $userId, 'public');
    }

    private function currentBalance(int $userId, ?int $excludeTransactionId = null): float
    {
        $incomeQuery = Transaction::query()
            ->where('idUser', $userId)
            ->where('type', 'income');

        $expenseQuery = Transaction::query()
            ->where('idUser', $userId)
            ->where('type', 'expense');

        if ($excludeTransactionId !== null) {
            $incomeQuery->where('idTransaction', '!=', $excludeTransactionId);
            $expenseQuery->where('idTransaction', '!=', $excludeTransactionId);
        }

        $income = (float) $incomeQuery->sum('amount');
        $expense = (float) $expenseQuery->sum('amount');

        return $income - $expense;
    }

    private function resolveFundingSource(int $userId, string $sourceName): ?FundingSource
    {
        return FundingSource::query()
            ->where('idUser', $userId)
            ->whereRaw('LOWER(name) = ?', [strtolower($sourceName)])
            ->first();
    }

    private function resolveFundingSourceById(int $userId, int $fundingSourceId): ?FundingSource
    {
        return FundingSource::query()
            ->where('idUser', $userId)
            ->where('idFundingSource', $fundingSourceId)
            ->first();
    }

    private function resolveResource(int $userId, int $resourceId): ?Resource
    {
        return Resource::query()
            ->where('idUser', $userId)
            ->where('idResource', $resourceId)
            ->first();
    }

    private function resolveResourceBySourceName(int $userId, string $sourceName): ?Resource
    {
        return Resource::query()
            ->where('idUser', $userId)
            ->whereRaw('LOWER(source) = ?', [strtolower($sourceName)])
            ->first();
    }

    private function sourceBalance(int $userId, string $sourceName, float $initialBalance, ?int $excludeTransactionId = null): float
    {
        $incomeQuery = Transaction::query()
            ->where('idUser', $userId)
            ->whereRaw('LOWER(source) = ?', [strtolower($sourceName)])
            ->where('type', 'income');

        $expenseQuery = Transaction::query()
            ->where('idUser', $userId)
            ->whereRaw('LOWER(source) = ?', [strtolower($sourceName)])
            ->where('type', 'expense');

        if ($excludeTransactionId !== null) {
            $incomeQuery->where('idTransaction', '!=', $excludeTransactionId);
            $expenseQuery->where('idTransaction', '!=', $excludeTransactionId);
        }

        $income = (float) $incomeQuery->sum('amount');
        $expense = (float) $expenseQuery->sum('amount');

        return round($initialBalance + $income - $expense, 2);
    }
}
