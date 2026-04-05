<?php

namespace App\Http\Middleware;

use App\Models\AuthToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PendingTwoFactorTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return new JsonResponse([
                'message' => 'Unauthorized',
            ], 401);
        }

        $tokenHash = hash('sha256', trim($matches[1]));

        $token = AuthToken::query()
            ->where('tokenHash', $tokenHash)
            ->where('type', 'two_factor')
            ->whereNull('revokedAt')
            ->where('expiresAt', '>', now())
            ->first();

        if (!$token || !$token->user) {
            return new JsonResponse([
                'message' => 'Unauthorized',
            ], 401);
        }

        $request->attributes->set('auth_user', $token->user);
        $request->attributes->set('pending_auth_token', $token);

        return $next($request);
    }
}
