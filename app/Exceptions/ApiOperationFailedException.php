<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiOperationFailedException extends Exception
{
    public $data;

    /**
     * ApiOperationFailedException constructor.
     *
     * @param string    $message
     * @param int       $code
     * @param Exception|null $previous
     * @param mixed     $data
     */
    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null, mixed $data = null)
    {
        if ($code === 0) {
            $code = Response::HTTP_BAD_REQUEST;
        }

        parent::__construct($message, $code, $previous);

        $this->data = $data;
    }

    /**
     * Render the exception into an HTTP response.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|bool
     */
    public function render(Request $request): \Illuminate\Http\JsonResponse|bool
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            $response = [
                'success' => false,
                'message' => __($this->getMessage()),
            ];

            if (!empty($this->data)) {
                $response['data'] = $this->data;
            }

            return response()->json($response, $this->getCode() ?: Response::HTTP_BAD_REQUEST);
        }

        return false;
    }
}
