<?php

namespace App\Http\Resources\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponse
{
    public static function success(string $message = 'Success', mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function created(string $message = 'Created', mixed $data = null): JsonResponse
    {
        return static::success($message, $data, 201);
    }

    public static function deleted(string $message = 'Deleted'): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message], 200);
    }

    public static function error(string $message = 'Error', int $status = 500, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    public static function unauthorized(string $message = 'Unauthenticated'): JsonResponse
    {
        return static::error($message, 401);
    }

    public static function validationErrors(array $errors, string $message = 'Validation Error'): JsonResponse
    {
        return static::error($message, 422, $errors);
    }

    public static function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return static::error($message, 403);
    }

    public static function notFound(string $message = 'Not Found'): JsonResponse
    {
        return static::error($message, 404);
    }

    /**
     * ADR-004 paginated envelope: data.data (resources), data.meta, data.links.
     *
     * @param  class-string<JsonResource>  $resource
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resource, string $message = 'Success'): JsonResponse
    {
        return static::success($message, [
            'data' => $resource::collection($paginator->getCollection())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
