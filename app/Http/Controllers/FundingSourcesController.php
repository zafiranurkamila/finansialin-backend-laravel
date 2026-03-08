<?php

namespace App\Http\Controllers;

use App\Models\FundingSource;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FundingSourcesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $sources = FundingSource::query()
            ->where('idUser', $user->idUser)
            ->orderBy('name')
            ->get()
            ->map(function (FundingSource $source) use ($user) {
                return [
                    'idFundingSource' => $source->idFundingSource,
                    'idUser' => $source->idUser,
                    'name' => $source->name,
                    'initialBalance' => (float) $source->initialBalance,
                    'availableBalance' => $this->availableBalance((int) $user->idUser, $source->name, (float) $source->initialBalance),
                    'createdAt' => $source->createdAt,
                    'updatedAt' => $source->updatedAt,
                ];
            })
            ->values();

        return response()->json($sources);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'initialBalance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $name = trim((string) $request->input('name'));
        $initialBalance = (float) ($request->input('initialBalance', 0));

        $exists = FundingSource::query()
            ->where('idUser', $user->idUser)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Funding source already exists'], 409);
        }

        $source = FundingSource::query()->create([
            'idUser' => $user->idUser,
            'name' => $name,
            'initialBalance' => $initialBalance,
        ]);

        return response()->json([
            'idFundingSource' => $source->idFundingSource,
            'idUser' => $source->idUser,
            'name' => $source->name,
            'initialBalance' => (float) $source->initialBalance,
            'availableBalance' => $this->availableBalance((int) $user->idUser, $source->name, (float) $source->initialBalance),
            'createdAt' => $source->createdAt,
            'updatedAt' => $source->updatedAt,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $source = FundingSource::query()->where('idFundingSource', $id)->first();
        if (!$source || (int) $source->idUser !== (int) $user->idUser) {
            return response()->json(['message' => 'Funding source not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'min:2', 'max:80'],
            'initialBalance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $newName = $request->has('name') ? trim((string) $request->input('name')) : $source->name;

        if (strtolower($newName) !== strtolower($source->name)) {
            $exists = FundingSource::query()
                ->where('idUser', $user->idUser)
                ->where('idFundingSource', '!=', $source->idFundingSource)
                ->whereRaw('LOWER(name) = ?', [strtolower($newName)])
                ->exists();

            if ($exists) {
                return response()->json(['message' => 'Funding source already exists'], 409);
            }
        }

        $source->update([
            'name' => $newName,
            'initialBalance' => $request->has('initialBalance')
                ? (float) $request->input('initialBalance')
                : (float) $source->initialBalance,
        ]);

        return response()->json([
            'idFundingSource' => $source->idFundingSource,
            'idUser' => $source->idUser,
            'name' => $source->name,
            'initialBalance' => (float) $source->initialBalance,
            'availableBalance' => $this->availableBalance((int) $user->idUser, $source->name, (float) $source->initialBalance),
            'createdAt' => $source->createdAt,
            'updatedAt' => $source->updatedAt,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $source = FundingSource::query()->where('idFundingSource', $id)->first();
        if (!$source || (int) $source->idUser !== (int) $user->idUser) {
            return response()->json(['message' => 'Funding source not found'], 404);
        }

        $used = Transaction::query()
            ->where('idUser', $user->idUser)
            ->whereRaw('LOWER(source) = ?', [strtolower($source->name)])
            ->exists();

        if ($used) {
            return response()->json(['message' => 'Funding source is used in transactions'], 409);
        }

        $source->delete();

        return response()->json(['message' => 'Funding source deleted']);
    }

    private function availableBalance(int $userId, string $sourceName, float $initialBalance): float
    {
        $income = (float) Transaction::query()
            ->where('idUser', $userId)
            ->whereRaw('LOWER(source) = ?', [strtolower($sourceName)])
            ->where('type', 'income')
            ->sum('amount');

        $expense = (float) Transaction::query()
            ->where('idUser', $userId)
            ->whereRaw('LOWER(source) = ?', [strtolower($sourceName)])
            ->where('type', 'expense')
            ->sum('amount');

        return round($initialBalance + $income - $expense, 2);
    }
}
