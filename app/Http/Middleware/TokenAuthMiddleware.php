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

        $token = AuthToken::query()
            ->where('tokenHash', $tokenHash)
            ->where('type', 'access')
            ->whereNull('revokedAt')
            ->where('expiresAt', '>', now())
            ->first();

        if (!$token || !$token->user) {
            return new JsonResponse([
                'message' => 'Unauthorized',
            ], 401);
        }

        $request->attributes->set('auth_user', $token->user);

        return $next($request);
    }
}
