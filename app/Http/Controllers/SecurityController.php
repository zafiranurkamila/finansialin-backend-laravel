<?php

namespace App\Http\Controllers;

use App\Models\SecurityOtp;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SecurityController extends Controller
{
    public function sendEmailVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        if ($user->emailVerifiedAt !== null) {
            return response()->json([
                'message' => 'Email already verified',
            ]);
        }

        $otp = SecurityOtp::issue($user, 'email_verification', 10);
        $mailWarning = null;

        try {
            $this->sendOtpMail($user->email, 'Kode Verifikasi Email', $otp['code']);
        } catch (Throwable $exception) {
            Log::error('Failed to send email verification OTP.', [
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);
            $mailWarning = 'Verification code generated, but email could not be sent right now.';
        }

        $response = [
            'message' => 'Verification code has been sent to your email',
            'expiresAt' => $otp['expiresAt'],
        ];

        if ($this->shouldExposeDebugOtp()) {
            $response['debugOtp'] = $otp['code'];
        }

        if ($mailWarning !== null) {
            $response['mailWarning'] = $mailWarning;
        }

        return response()->json($response);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'digits:6'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $ok = SecurityOtp::consume($user, 'email_verification', (string) $request->input('code'));
        if (!$ok) {
            return response()->json(['message' => 'Invalid or expired verification code'], 422);
        }

        $user->update(['emailVerifiedAt' => now()]);

        return response()->json([
            'message' => 'Email verified successfully',
            'emailVerifiedAt' => $user->fresh()->emailVerifiedAt,
        ]);
    }

    public function enableTwoFactor(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        if ((bool) $user->twoFactorEnabled) {
            return response()->json([
                'message' => 'Two-factor authentication is already enabled',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        if (!Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        $otp = SecurityOtp::issue($user, 'two_factor_enable', 10);
        $mailWarning = null;

        try {
            $this->sendOtpMail($user->email, 'Kode Aktivasi 2FA', $otp['code']);
        } catch (Throwable $exception) {
            Log::error('Failed to send 2FA enable OTP.', [
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);
            $mailWarning = '2FA code generated, but email could not be sent right now.';
        }

        $response = [
            'message' => '2FA activation code has been sent to your email',
            'expiresAt' => $otp['expiresAt'],
        ];

        if ($this->shouldExposeDebugOtp()) {
            $response['debugOtp'] = $otp['code'];
        }

        if ($mailWarning !== null) {
            $response['mailWarning'] = $mailWarning;
        }

        return response()->json($response);
    }

    public function verifyEnableTwoFactor(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'digits:6'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $ok = SecurityOtp::consume($user, 'two_factor_enable', (string) $request->input('code'));
        if (!$ok) {
            return response()->json(['message' => 'Invalid or expired verification code'], 422);
        }

        $user->update([
            'twoFactorEnabled' => true,
            'twoFactorConfirmedAt' => now(),
        ]);

        return response()->json([
            'message' => 'Two-factor authentication enabled',
            'twoFactorEnabled' => true,
            'twoFactorConfirmedAt' => $user->fresh()->twoFactorConfirmedAt,
        ]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        if (!(bool) $user->twoFactorEnabled) {
            return response()->json([
                'message' => 'Two-factor authentication is already disabled',
            ]);
        }

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        if (!Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        $user->update([
            'twoFactorEnabled' => false,
            'twoFactorConfirmedAt' => null,
        ]);

        return response()->json([
            'message' => 'Two-factor authentication disabled',
            'twoFactorEnabled' => false,
        ]);
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

    private function shouldExposeDebugOtp(): bool
    {
        return in_array((string) config('app.env'), ['local', 'testing'], true) || (bool) config('app.debug');
    }
}
