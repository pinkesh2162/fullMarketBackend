<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\App;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $langHeader = $request->header('lang')
            ?? $request->header('X-Lang')
            ?? $request->header('X-Locale');

        if ($langHeader === null || trim((string) $langHeader) === '') {
            $accept = (string) $request->header('Accept-Language', '');
            if ($accept !== '') {
                $first = trim(explode(',', $accept)[0]);
                $tag = strtolower(trim(explode(';', $first)[0]));
                // es-MX → es
                if (preg_match('/^([a-z]{2})(?:-[a-z]{2,})?$/i', $tag, $m)) {
                    $langHeader = strtolower($m[1]);
                }
            }
        }
        if ($langHeader === null || trim((string) $langHeader) === '') {
            $langHeader = 'en';
        }

        $normalized = strtolower(trim((string) $langHeader));

        // Map 'english' and 'spanish' to 'en' and 'es'. Default must use $normalized so `ES`/`EN` work (not raw header).
        $locale = match ($normalized) {
            'spanish' => 'es',
            'english' => 'en',
            default => $normalized,
        };

        if (! in_array($locale, ['en', 'es'], true)) {
            $locale = 'en';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
