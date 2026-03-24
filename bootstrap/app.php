<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }
            
            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], Response::HTTP_UNAUTHORIZED);
            }
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $message = $e->getMessage();
                
                if (preg_match('@\\\\(\w+)\]@', $message, $matches)) {
                    $model = preg_replace('/Table/i', '', $matches[1]);
                    $message = "{$model} not found.";
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], Response::HTTP_NOT_FOUND);
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            $message = $e->validator->errors()->first();

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return redirect()->back()->withInput()->withErrors($message);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $code = $e->getCode();
                if ($code < 100 || $code >= 600) {
                    $code = Response::HTTP_INTERNAL_SERVER_ERROR;
                }

                return response()->json([
                    'success' => false,
                    'message' => empty($e->getMessage()) ? 'Server Error' : $e->getMessage(),
                ], $code);
            }
        });
    })->create();
