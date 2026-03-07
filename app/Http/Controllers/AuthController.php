<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordMail;
use App\Mail\VerifyEmailMail;
use App\Models\AuthToken;
use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'name' => ['nullable', 'string', 'min:2', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = User::create([
            'email' => $request->string('email')->lower()->toString(),
            'password' => Hash::make($request->string('password')->toString()),
            'name' => $request->input('name'),
        ]);

        $verification = $this->issueEmailVerificationToken($user);
        $this->sendVerificationMail($user, $verification['plain']);

        $tokens = $this->issueTokens($user);

        $response = array_merge($tokens, [
            'message' => 'Registration successful. Please verify your email.',
            'user' => $this->userPayload($user),
        ]);

        if ($this->shouldExposeDebugToken()) {
            $response['verification'] = [
                'token' => $verification['plain'],
                'expiresAt' => $verification['expiresAt']->toISOString(),
            ];
        }

        return response()->json($response, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = User::query()->where('email', $request->string('email')->lower()->toString())->first();

        if (!$user || !Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        if ($this->requiresVerifiedEmail() && $user->emailVerifiedAt === null) {
            return response()->json([
                'message' => 'Email is not verified',
            ], 403);
        }

        $tokens = $this->issueTokens($user);

        return response()->json(array_merge($tokens, [
            'user' => $this->userPayload($user),
        ]));
    }

    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = (string) ($request->input('refresh_token') ?? $request->input('refreshToken') ?? '');

        if ($refreshToken === '') {
            return response()->json([
                'message' => 'refresh_token is required',
            ], 422);
        }

        $token = AuthToken::query()
            ->where('tokenHash', hash('sha256', $refreshToken))
            ->where('type', 'refresh')
            ->whereNull('revokedAt')
            ->where('expiresAt', '>', now())
            ->first();

        if (!$token || !$token->user) {
            return response()->json([
                'message' => 'Invalid refresh token',
            ], 401);
        }

        $token->update(['revokedAt' => now()]);

        return response()->json($this->issueTokens($token->user));
    }

    public function profile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        return response()->json([
            'id' => $user->idUser,
            'idUser' => $user->idUser,
            'email' => $user->email,
            'name' => $user->name,
            'createdAt' => $user->createdAt,
            'emailVerifiedAt' => $user->emailVerifiedAt,
            'isEmailVerified' => $user->emailVerifiedAt !== null,
            'user' => [
                'userId' => $user->idUser,
                'email' => $user->email,
                'name' => $user->name,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $header = $request->header('Authorization', '');

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $tokenHash = hash('sha256', trim($matches[1]));
            AuthToken::query()->where('tokenHash', $tokenHash)->update(['revokedAt' => now()]);
        }

        return response()->json(['message' => 'Logout successful']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $email = $request->string('email')->lower()->toString();
        $user = User::query()->where('email', $email)->first();

        $response = [
            'message' => 'If an account exists with this email, a password reset link has been sent.',
            'success' => true,
        ];

        if (!$user) {
            return response()->json($response);
        }

        $token = Password::broker()->createToken($user);
        $this->sendResetPasswordMail($user, $token);

        if ($this->shouldExposeDebugToken()) {
            $response['reset'] = [
                'token' => $token,
                'email' => $email,
            ];
        }

        return response()->json($response);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $status = Password::broker()->reset([
            'email' => $request->string('email')->lower()->toString(),
            'token' => $request->string('token')->toString(),
            'password' => $request->string('password')->toString(),
        ], function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
            ])->save();

            UserNotification::create([
                'idUser' => $user->idUser,
                'type' => 'PASSWORD_RESET',
                'read' => false,
                'message' => 'Password Anda telah berhasil direset melalui email.',
            ]);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Invalid or expired reset token',
            ], 400);
        }

        return response()->json([
            'message' => 'Password reset successful',
            'success' => true,
        ]);
    }

    public function sendVerificationEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $email = $request->string('email')->lower()->toString();
        $user = User::query()->where('email', $email)->first();

        $response = [
            'message' => 'If your email exists, a verification link has been sent.',
            'success' => true,
        ];

        if (!$user || $user->emailVerifiedAt !== null) {
            return response()->json($response);
        }

        $verification = $this->issueEmailVerificationToken($user);
        $this->sendVerificationMail($user, $verification['plain']);

        if ($this->shouldExposeDebugToken()) {
            $response['verification'] = [
                'token' => $verification['plain'],
                'expiresAt' => $verification['expiresAt']->toISOString(),
            ];
        }

        return response()->json($response);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $email = $request->string('email')->lower()->toString();
        $tokenHash = hash('sha256', $request->string('token')->toString());
        $user = User::query()->where('email', $email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Invalid verification token',
            ], 400);
        }

        $verificationToken = EmailVerificationToken::query()
            ->where('idUser', $user->idUser)
            ->where('tokenHash', $tokenHash)
            ->whereNull('verifiedAt')
            ->where('expiresAt', '>', now())
            ->latest('idVerificationToken')
            ->first();

        if (!$verificationToken) {
            return response()->json([
                'message' => 'Invalid verification token',
            ], 400);
        }

        $verificationToken->update([
            'verifiedAt' => now(),
        ]);

        if ($user->emailVerifiedAt === null) {
            $user->update([
                'emailVerifiedAt' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Email verified successfully',
            'success' => true,
        ]);
    }

    private function issueEmailVerificationToken(User $user): array
    {
        EmailVerificationToken::query()
            ->where('idUser', $user->idUser)
            ->whereNull('verifiedAt')
            ->delete();

        $plain = Str::random(64);
        $expiresAt = now()->addMinutes((int) env('EMAIL_VERIFICATION_TTL_MINUTES', 60));

        EmailVerificationToken::query()->create([
            'idUser' => $user->idUser,
            'tokenHash' => hash('sha256', $plain),
            'expiresAt' => $expiresAt,
        ]);

        return [
            'plain' => $plain,
            'expiresAt' => $expiresAt,
        ];
    }

    private function sendVerificationMail(User $user, string $plainToken): void
    {
        $verificationUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3001'), '/')
            . '/verify-email?token=' . urlencode($plainToken)
            . '&email=' . urlencode($user->email);

        Mail::to($user->email)->send(new VerifyEmailMail($user->email, $verificationUrl));
    }

    private function sendResetPasswordMail(User $user, string $plainToken): void
    {
        $resetUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3001'), '/')
            . '/reset-password?token=' . urlencode($plainToken)
            . '&email=' . urlencode($user->email);

        Mail::to($user->email)->send(new ResetPasswordMail($user->email, $resetUrl));
    }

    private function shouldExposeDebugToken(): bool
    {
        return app()->environment(['local', 'testing']) || config('app.debug');
    }

    private function requiresVerifiedEmail(): bool
    {
        return (bool) env('AUTH_REQUIRE_VERIFIED_EMAIL', false);
    }

    private function issueTokens(User $user): array
    {
        $accessExp = now()->addMinutes((int) env('ACCESS_TOKEN_TTL_MINUTES', 15));
        $refreshExp = now()->addDays((int) env('REFRESH_TOKEN_TTL_DAYS', 7));

        $access = AuthToken::issue($user, 'access', $accessExp);
        $refresh = AuthToken::issue($user, 'refresh', $refreshExp);

        return [
            'accessToken' => $access['plain'],
            'refreshToken' => $refresh['plain'],
            'access_token' => $access['plain'],
            'refresh_token' => $refresh['plain'],
            'expiresIn' => '15m',
        ];
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->idUser,
            'idUser' => $user->idUser,
            'email' => $user->email,
            'name' => $user->name,
            'createdAt' => $user->createdAt,
            'emailVerifiedAt' => $user->emailVerifiedAt,
            'isEmailVerified' => $user->emailVerifiedAt !== null,
        ];
    }
}
