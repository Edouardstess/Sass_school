<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The one place API response shapes are defined.
 *
 * Every endpoint answers with the same envelope, so a client can write one
 * error handler and one unwrapper instead of one per endpoint:
 *
 *   { "success": true,  "data": …, "message": …, "meta": … }
 *   { "success": false, "message": …, "errors": { field: [ … ] } }
 */
final class ApiResponse
{
    /** @param array<string, mixed> $meta */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return self::success($data, $message ?? __('responses.created'), 201);
    }

    /** 204 carries no body by definition, so no envelope is emitted. */
    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /** @param array<string, list<string>> $errors */
    public static function error(
        string $message,
        int $status = 400,
        array $errors = [],
        ?string $code = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status);
    }

    /**
     * Wrap a paginator, moving pagination into `meta` and dropping Laravel's
     * default `links`/`meta` duplication.
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        ?string $resourceClass = null,
        ?string $message = null,
    ): JsonResponse {
        $items = $resourceClass !== null
            ? $resourceClass::collection($paginator->getCollection())
            : $paginator->getCollection();

        return self::success(
            data: $items,
            message: $message,
            meta: [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        );
    }

    /** Unwraps a resource so the envelope is never nested inside `data.data`. */
    public static function resource(
        JsonResource|ResourceCollection $resource,
        ?string $message = null,
        int $status = 200,
    ): JsonResponse {
        return self::success($resource, $message, $status);
    }
}
