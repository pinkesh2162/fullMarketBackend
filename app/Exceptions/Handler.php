<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
            }
        });

        $this->renderable(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $message = $e->getMessage();

                if (preg_match('@\\\\(\w+)\]@', $message, $matches)) {
                    $model = preg_replace('/Table/i', '', $matches[1]);
                    $message = "{$model} not found.";
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        });

        $this->renderable(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) {
            $message = $e->validator->errors()->first();

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return redirect()->back()->withInput()->withErrors($message);
        });

        $this->renderable(function (Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                // If it's a built-in HTTP exception, let Laravel handle it, or we can handle it generically
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $code = $e->getStatusCode();
                } else {
                    $code = $e->getCode();
                    if ($code < 100 || $code >= 600) {
                        $code = \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR;
                    }
                }

                return response()->json([
                    'success' => false,
                    'message' => empty($e->getMessage()) ? 'Server Error' : $e->getMessage(),
                ], $code);
            }
        });

        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
