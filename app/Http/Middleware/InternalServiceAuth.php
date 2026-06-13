<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalServiceAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = env('INTERNAL_API_TOKEN');
        $headerToken = $request->header('X-Internal-Token');

        if (!$token || $token !== $headerToken) {
            return response()->json(['message' => 'Unauthorized internal access'], 401);
        }

        return $next($request);
    }
}
