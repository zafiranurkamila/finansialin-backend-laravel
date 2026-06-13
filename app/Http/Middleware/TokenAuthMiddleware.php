<?php

namespace App\Http\Middleware;

use App\Models\AuthToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TokenAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return new JsonResponse([
                'message' => 'Unauthorized',
            ], 401);
        }

        $plainToken = trim($matches[1]);
        $tokenHash = hash('sha256', $plainToken);
        $cacheKey = "auth_token:{$tokenHash}";

        $token = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($tokenHash) {
            return AuthToken::with('user')->where('tokenHash', $tokenHash)->first();
        });

        if (!$token || $token->type !== 'access' || $token->revokedAt !== null || $token->expiresAt <= now() || !$token->user) {
            return new JsonResponse([
                'message' => 'Unauthorized',
            ], 401);
        }

        $request->attributes->set('auth_user', $token->user);

        return $next($request);
    }
}
