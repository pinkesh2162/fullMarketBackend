<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }
            
            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }
        });
        
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'status' => false,
                    'message' => config('app.debug')
                        ? $e->getMessage()
                        : 'Something went wrong',
                ], 500);
            }
        });
    })->create();
