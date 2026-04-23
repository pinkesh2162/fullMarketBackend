<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'status' => 401,
                'message' => __('Unauthenticated.'),
            ], 401);
        }

        $role = (string) ($user->getAttributes()['role'] ?? User::ROLE_USER);
        if ($role !== User::ROLE_ADMIN) {
            return response()->json([
                'status' => 403,
                'message' => __('Admin privileges required.'),
            ], 403);
        }

        return $next($request);
    }
}
