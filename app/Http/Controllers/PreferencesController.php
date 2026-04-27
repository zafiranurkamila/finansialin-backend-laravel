<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PreferencesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $preference = UserPreference::query()->firstOrCreate(
            ['idUser' => $user->idUser],
            [
                'theme' => 'light',
                'hideBalance' => false,
                'dailyReminder' => true,
                'budgetLimitAlert' => true,
                'weeklySummary' => true,
            ]
        );

        return response()->json($preference);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'theme' => ['nullable', 'in:light,dark,system'],
            'hideBalance' => ['nullable', 'boolean'],
            'dailyReminder' => ['nullable', 'boolean'],
            'budgetLimitAlert' => ['nullable', 'boolean'],
            'weeklySummary' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $preference = UserPreference::query()->firstOrCreate(
            ['idUser' => $user->idUser],
            [
                'theme' => 'light',
                'hideBalance' => false,
                'dailyReminder' => true,
                'budgetLimitAlert' => true,
                'weeklySummary' => true,
            ]
        );

        $payload = [];
        foreach (['theme', 'hideBalance', 'dailyReminder', 'budgetLimitAlert', 'weeklySummary'] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }

        if ($payload !== []) {
            $preference->update($payload);
        }

        return response()->json($preference->fresh());
    }
}
