<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebhookIntegrationsController extends Controller
{
    public function ingestQrisEmail(Request $request): JsonResponse
    {
        $expectedSecret = (string) env('N8N_QRIS_WEBHOOK_SECRET', '');
        $providedSecret = (string) $request->header('X-Webhook-Secret', '');

        if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            return response()->json(['message' => 'Unauthorized webhook request'], 401);
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'amount' => ['required'],
            'paidAt' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'string', 'max:120'],
            'categoryName' => ['nullable', 'string', 'max:100'],
            'merchant' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = User::query()
            ->where('email', strtolower((string) $request->input('email')))
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'User not found for provided email',
            ], 404);
        }

        $amount = $this->normalizeAmount((string) $request->input('amount'));
        if ($amount <= 0) {
            return response()->json([
                'message' => 'Invalid amount',
            ], 422);
        }

        $category = $this->resolveExpenseCategory(
            (int) $user->idUser,
            $request->input('categoryName')
        );

        $description = trim((string) ($request->input('description') ?? ''));
        $merchant = trim((string) ($request->input('merchant') ?? ''));

        if ($description === '' && $merchant !== '') {
            $description = 'QRIS payment to ' . $merchant;
        }

        $transaction = Transaction::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $category?->idCategory,
            'type' => 'expense',
            'amount' => number_format($amount, 2, '.', ''),
            'description' => $description !== '' ? $description : 'QRIS payment from email automation',
            'date' => $request->filled('paidAt') ? $request->input('paidAt') : now(),
            'source' => (string) ($request->input('source') ?: 'qris-email-automation'),
        ]);

        return response()->json([
            'message' => 'QRIS email transaction ingested',
            'data' => $transaction->fresh(['category']),
        ], 201);
    }

    private function resolveExpenseCategory(int $userId, mixed $categoryName): ?Category
    {
        $name = trim((string) ($categoryName ?? ''));

        if ($name !== '') {
            $match = Category::query()
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->where('type', 'expense')
                ->where(function ($query) use ($userId) {
                    $query->whereNull('idUser')->orWhere('idUser', $userId);
                })
                ->orderByRaw('CASE WHEN idUser IS NULL THEN 1 ELSE 0 END')
                ->first();

            if ($match) {
                return $match;
            }
        }

        return Category::query()
            ->where('type', 'expense')
            ->where(function ($query) use ($userId) {
                $query->whereNull('idUser')->orWhere('idUser', $userId);
            })
            ->orderByRaw('CASE WHEN idUser IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')
            ->first();
    }

    private function normalizeAmount(string $rawAmount): float
    {
        $value = trim($rawAmount);
        $value = preg_replace('/[^0-9,\.]/', '', $value) ?? '';

        if ($value === '') {
            return 0.0;
        }

        // Handle thousand separators and decimal comma from Indonesian formatting.
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return (float) $value;
    }
}
