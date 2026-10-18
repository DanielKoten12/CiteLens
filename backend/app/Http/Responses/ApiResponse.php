<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Spatie\LaravelData\Data;

/**
 * Builds the canonical success envelopes (`docs/API_SPEC.md` §2.4):
 *
 * - single/action: `{ "data": …, "message"?: "…" }`
 * - collection:    `{ "data": [ … ], "meta": { current_page, per_page, total, last_page } }`
 * - `204`:         no body
 *
 * Controllers must return through these helpers instead of hand-building envelopes.
 */
final class ApiResponse
{
    /**
     * A single resource or action response.
     */
    public static function single(Data|array|null $data, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }

    /**
     * A `201 Created` response.
     */
    public static function created(Data|array|null $data, ?string $message = null): JsonResponse
    {
        return self::single($data, $message, 201);
    }

    /**
     * A `202 Accepted` response.
     */
    public static function accepted(Data|array|null $data, ?string $message = null): JsonResponse
    {
        return self::single($data, $message, 202);
    }

    /**
     * A paginated collection response with the canonical `meta` block.
     *
     * @param  class-string<Data>  $dataClass  DTO used to map each paginator item.
     */
    public static function collection(LengthAwarePaginator $paginator, string $dataClass): JsonResponse
    {
        $items = array_map(
            static fn (mixed $item): Data => $item instanceof Data ? $item : $dataClass::from($item),
            $paginator->items(),
        );

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * A `204 No Content` response.
     */
    public static function noContent(): Response
    {
        return response()->noContent();
    }
}
