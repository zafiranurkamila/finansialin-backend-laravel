<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordMail;
use App\Models\AuthToken;
use App\Models\SecurityOtp;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ResourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'name' => ['nullable', 'string', 'min:2', 'max:100'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+\-\s()]{8,25}$/'],
            'salary_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $phone = $request->filled('phone')
            ? $this->normalizePhone($request->string('phone')->toString())
            : null;

        if ($phone !== null && $phone !== '') {
            $phoneExists = User::query()->where('phone', $phone)->exists();
            if ($phoneExists) {
                return response()->json([
                    'message' => 'The phone has already been taken.',
                ], 422);
            }
        }

        $user = User::query()->create([
            'email' => $request->string('email')->lower()->toString(),
            'password' => Hash::make($request->string('password')->toString()),
            'name' => $request->input('name'),
            'phone' => $phone,
            'salary_date' => $request->input('salary_date'),
        ]);

        // Initialize 3 default resources (mbanking, emoney, cash)
        try {
            ResourceService::initializeDefaultResources($user);
        } catch (Throwable $exception) {
            Log::error('Failed to initialize resources for new user.', [
                'userId' => $user->idUser,
                'error' => $exception->getMessage(),
            ]);
        }

        $tokens = $this->issueTokens($user);
        $otp = SecurityOtp::issue($user, 'email_verification', 10);
        $mailWarning = null;

        try {
            $this->sendOtpMail($user->email, 'Kode Verifikasi Email', $otp['code']);
        } catch (Throwable $exception) {
            Log::error('Failed to send email verification OTP during registration.', [
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);
            $mailWarning = 'Verification code generated, but email could not be sent right now.';
        }

        $response = array_merge($tokens, [
            'message' => 'Registration successful',
            'user' => $this->userPayload($user),
            'emailVerification' => [
                'required' => true,
                'expiresAt' => $otp['expiresAt'],
            ],
        ]);

        if ($this->shouldExposeDebugToken()) {
            $response['debugOtp'] = $otp['code'];
        }

        if ($mailWarning !== null) {
            $response['mailWarning'] = $mailWarning;
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

        if ((bool) $user->twoFactorEnabled) {
            AuthToken::query()
                ->where('idUser', $user->idUser)
                ->where('type', 'two_factor')
                ->whereNull('revokedAt')
                ->update(['revokedAt' => now()]);

            $pending = AuthToken::issue($user, 'two_factor', now()->addMinutes(10));
            $otp = SecurityOtp::issue($user, 'login_2fa', 10);

            $mailWarning = null;
            try {
                $this->sendOtpMail($user->email, 'Kode OTP Login Finansialin', $otp['code']);
            } catch (Throwable $exception) {
                Log::error('Failed to send login 2FA OTP.', [
                    'email' => $user->email,
                    'error' => $exception->getMessage(),
                ]);
                $mailWarning = 'OTP generated, but email could not be sent right now.';
            }

            $response = [
                'requiresTwoFactor' => true,
                'twoFactorToken' => $pending['plain'],
                'message' => 'OTP has been sent to your email',
                'expiresIn' => '10m',
            ];

            if ($this->shouldExposeDebugToken()) {
                $response['debugOtp'] = $otp['code'];
            }

            if ($mailWarning !== null) {
                $response['mailWarning'] = $mailWarning;
            }

            return response()->json($response, 202);
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

    public function verifyLoginTwoFactor(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');
        /** @var AuthToken $pendingToken */
        $pendingToken = $request->attributes->get('pending_auth_token');

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'digits:6'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $ok = SecurityOtp::consume($user, 'login_2fa', (string) $request->input('code'));
        if (!$ok) {
            return response()->json([
                'message' => 'Invalid or expired verification code',
            ], 422);
        }

        $pendingToken->update(['revokedAt' => now()]);
        $tokens = $this->issueTokens($user);

        return response()->json(array_merge($tokens, [
            'user' => $this->userPayload($user),
        ]));
    }

    public function profile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        return response()->json([
            'id' => $user->idUser,
            'idUser' => $user->idUser,
            'email' => $user->email,
            'phone' => $user->phone,
            'name' => $user->name,
            'createdAt' => $user->createdAt,
            'emailVerifiedAt' => $user->emailVerifiedAt,
            'isEmailVerified' => $user->emailVerifiedAt !== null,
            'phoneVerifiedAt' => $user->phoneVerifiedAt,
            'isPhoneVerified' => $user->phoneVerifiedAt !== null,
            'twoFactorEnabled' => (bool) $user->twoFactorEnabled,
            'twoFactorConfirmedAt' => $user->twoFactorConfirmedAt,
            'user' => [
                'userId' => $user->idUser,
                'email' => $user->email,
                'phone' => $user->phone,
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
        $mailWarning = null;

        try {
            $this->sendResetPasswordMail($user, $token);
        } catch (Throwable $exception) {
            Log::error('Failed to send reset password email.', [
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);

            $mailWarning = 'Reset token generated, but email could not be sent right now.';
        }

        if ($this->shouldExposeDebugToken()) {
            $response['reset'] = [
                'token' => $token,
                'email' => $email,
            ];
        }

        if ($mailWarning !== null) {
            $response['mailWarning'] = $mailWarning;
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

    private function sendResetPasswordMail(User $user, string $plainToken): void
    {
        $resetUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3001'), '/')
            . '/reset-password?token=' . urlencode($plainToken)
            . '&email=' . urlencode($user->email);

        Mail::to($user->email)->send(new ResetPasswordMail($user->email, $resetUrl));
    }

    private function shouldExposeDebugToken(): bool
    {
        return in_array((string) config('app.env'), ['local', 'testing'], true) || (bool) config('app.debug');
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        }

        if (!str_starts_with($digits, '62')) {
            $digits = '62' . $digits;
        }

        return '+' . $digits;
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
            'phone' => $user->phone,
            'name' => $user->name,
            'createdAt' => $user->createdAt,
            'emailVerifiedAt' => $user->emailVerifiedAt,
            'isEmailVerified' => $user->emailVerifiedAt !== null,
            'phoneVerifiedAt' => $user->phoneVerifiedAt,
            'isPhoneVerified' => $user->phoneVerifiedAt !== null,
            'twoFactorEnabled' => (bool) $user->twoFactorEnabled,
            'twoFactorConfirmedAt' => $user->twoFactorConfirmedAt,
        ];
    }

    private function sendOtpMail(string $email, string $subject, string $code): void
    {
        Mail::raw(
            "Kode OTP Anda: {$code}\nBerlaku selama 10 menit.",
            static function ($message) use ($email, $subject): void {
                $message->to($email)->subject($subject);
            }
        );
    }
}
