<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureUserPlatform
{
    /**
     * Capture X-Platform for authenticated app users.
     *
     * Missing header keeps the previous known platform.
     * Invalid non-empty values are normalized to "unknown".
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $rawHeader = $request->header('X-Platform');
        if (! is_string($rawHeader) || trim($rawHeader) === '') {
            return $next($request);
        }

        $normalized = User::normalizePlatform($rawHeader);
        $updates = [];

        if ((string) ($user->platform ?? User::PLATFORM_UNKNOWN) !== $normalized) {
            $updates['platform'] = $normalized;
        }
        $updates['last_platform_seen_at'] = now();

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }

        return $next($request);
    }
}
