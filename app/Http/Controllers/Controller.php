<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public const HTTP_OK = 200;

    public const HTTP_CREATED = 201;

    public const HTTP_NO_CONTENT = 204;

    public const HTTP_BAD_REQUEST = 400;

    public const HTTP_UNAUTHORIZED = 401;

    public const HTTP_FORBIDDEN = 403;

    public const HTTP_NOT_FOUND = 404;

    public const HTTP_CONFLICT = 409;

    public const HTTP_UNPROCESSABLE_ENTITY = 422;

    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    /**
     * Returns a standardized validation error response
     *
     * @param  string  $message  The main error message
     * @param  array|\Illuminate\Support\MessageBag  $errors  Validation errors
     */
    public function validationFailed(string $message, $errors): JsonResponse
    {
        return Response::json([
            // 'success' => false,
            'status' => self::HTTP_UNPROCESSABLE_ENTITY,
            'message' => __($message),
            'errors' => $errors,
            // 'timestamp' => now()->toISOString(),
        ], self::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Get audit information for a model
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    protected function getAuditInfo($model): array
    {
        return [
            'created_at' => $model->created_at?->toISOString(),
            'created_by' => $model->created_by,
            'creator_name' => $model->creator_name ?? 'Unknown',
            'updated_at' => $model->updated_at?->toISOString(),
            'updated_by' => $model->updated_by,
            'updater_name' => $model->updater_name ?? 'Unknown',
        ];
    }

    /**
     * Returns a standardized success response
     *
     * @param  string  $message  Success message
     * @param  mixed  $data  Optional data to include in response
     * @param  int  $statusCode  HTTP status code (default: 200)
     */
    public function actionSuccess(string $message, $data = null, int $statusCode = self::HTTP_OK): JsonResponse
    {
        $response = [
            // 'success' => true,
            'status' => $statusCode,
            'message' => __($message),
            // 'timestamp' => now()->toISOString(),
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return Response::json($response, $statusCode);
    }

    /**
     * Returns a standardized failure/error response
     *
     * @param  string  $message  Error message
     * @param  mixed  $data  Optional additional error data
     * @param  int  $statusCode  HTTP status code (default: 409)
     */
    public function actionFailure(string $message, $data = null, int $statusCode = self::HTTP_CONFLICT): JsonResponse
    {
        $response = [
            // 'success' => false,
            'status' => $statusCode,
            'message' => __($message),
            // 'timestamp' => now()->toISOString(),
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return Response::json($response, $statusCode);
    }

    /**
     * Legacy method for backward compatibility
     *
     * @deprecated Use actionSuccess() or actionFailure() instead
     *
     * @param  mixed  $result
     * @param  string|null  $message
     * @param  int  $code
     */
    public function sendResponse($result, $message = null, $code = 200): JsonResponse
    {
        return Response::json([
            'success' => $code >= 200 && $code < 300,
            'status' => $code,
            'data' => $result,
            'message' => __($message),
            // 'timestamp' => now()->toISOString(),
        ], $code);
    }

    /**
     * Returns a standardized "not found" response for empty lists
     *
     * @param  Request  $request  The current request
     * @param  int  $perPage  Number of items per page
     */
    public function notFoundList(Request $request, int $perPage = 15): JsonResponse
    {
        $emptyCollection = collect([]);
        $paginator = new LengthAwarePaginator(
            $emptyCollection,
            0,
            $perPage,
            1,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return $this->actionSuccess('No records found', $this->customizingResponseData($paginator));
    }

    /**
     * Formats pagination data for API responses
     *
     * @param  LengthAwarePaginator  $list  The paginated data
     * @return array Formatted pagination data
     */
    public function customizingResponseData(LengthAwarePaginator $list): array
    {
        return [
            'data' => $list->items(),
            'pagination' => [
                'current_page' => $list->currentPage(),
                'last_page' => $list->lastPage(),
                'per_page' => $list->perPage(),
                'total' => $list->total(),
                'from' => $list->firstItem(),
                'to' => $list->lastItem(),
                'has_more_pages' => $list->hasMorePages(),
            ],
        ];
    }

    /**
     * Returns a standardized error response for server errors
     *
     * @param  string  $message  Error message
     * @param  mixed  $data  Optional error data
     */
    public function serverError(string $message = 'Internal server error', $data = null): JsonResponse
    {
        return $this->actionFailure($message, $data, self::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Returns a standardized not found response
     *
     * @param  string  $message  Not found message
     */
    public function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->actionFailure($message, null, self::HTTP_NOT_FOUND);
    }

    /**
     * Returns a standardized unauthorized response
     *
     * @param  string  $message  Unauthorized message
     */
    public function unauthorized(string $message = 'Unauthorized access'): JsonResponse
    {
        return $this->actionFailure($message, null, self::HTTP_UNAUTHORIZED);
    }

    /**
     * Returns a standardized forbidden response
     *
     * @param  string  $message  Forbidden message
     */
    public function forbidden(string $message = 'Access forbidden'): JsonResponse
    {
        return $this->actionFailure($message, null, self::HTTP_FORBIDDEN);
    }
}
