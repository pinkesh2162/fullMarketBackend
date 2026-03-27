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
        $langHeader = $request->header('lang', 'en');

        // Map 'english' and 'spanish' to 'en' and 'es'
        $locale = match (strtolower($langHeader)) {
            'spanish' => 'es',
            'english' => 'en',
            default => $langHeader,
        };

        // Validate if locale is supported, default to 'en' if not
        if (!in_array($locale, ['en', 'es'])) {
            $locale = 'en';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
