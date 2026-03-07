<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UsersController extends Controller
{
    public function updateName(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user->update([
            'name' => $request->string('name')->toString(),
        ]);

        return response()->json([
            'idUser' => $user->idUser,
            'email' => $user->email,
            'name' => $user->name,
            'createdAt' => $user->createdAt,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'min:2', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $payload = [];
        if ($request->has('name')) {
            $payload['name'] = $request->input('name');
        }
        if ($request->has('email')) {
            $newEmail = strtolower((string) $request->input('email'));

            $emailUsed = User::query()
                ->where('email', $newEmail)
                ->where('idUser', '!=', $user->idUser)
                ->exists();

            if ($emailUsed) {
                return response()->json([
                    'message' => 'Email already in use',
                ], 400);
            }

            $payload['email'] = $newEmail;
        }

        if (!empty($payload)) {
            $user->update($payload);
        }

        return response()->json([
            'idUser' => $user->idUser,
            'email' => $user->email,
            'name' => $user->name,
            'createdAt' => $user->createdAt,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'oldPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:6'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $oldPassword = $request->string('oldPassword')->toString();
        $newPassword = $request->string('newPassword')->toString();

        if (!Hash::check($oldPassword, $user->password)) {
            return response()->json([
                'message' => 'Old password is incorrect',
            ], 401);
        }

        if (Hash::check($newPassword, $user->password)) {
            return response()->json([
                'message' => 'New password must be different from old password',
            ], 400);
        }

        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        UserNotification::create([
            'idUser' => $user->idUser,
            'type' => 'PASSWORD_RESET',
            'read' => false,
            'message' => 'Password Anda telah berhasil diubah.',
        ]);

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }
}
