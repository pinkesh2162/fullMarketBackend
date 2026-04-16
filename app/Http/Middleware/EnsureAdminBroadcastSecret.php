<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminBroadcastSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.admin_broadcast_secret', '');
        $token = (string) ($request->bearerToken() ?? '');

        if ($secret === '' || $token === '' || ! hash_equals($secret, $token)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
