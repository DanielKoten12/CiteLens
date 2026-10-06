<?php

namespace App\Http\Responses;

use App\Data\Error\ErrorResponseData;
use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Optional;

/**
 * Single source for the canonical error envelope (`docs/API_SPEC.md` §2.5)
 * and for the HTTP status → canonical code mapping used by framework exceptions.
 *
 * No other class may build the `error` payload or map a status to a code.
 */
final class ApiError
{
    /**
     * @var array<int, string>
     */
    private const array STATUS_TO_CODE = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        409 => 'CONFLICT',
        413 => 'PAYLOAD_TOO_LARGE',
        415 => 'UNSUPPORTED_MEDIA_TYPE',
        422 => 'VALIDATION_ERROR',
        429 => 'RATE_LIMITED',
        500 => 'SERVER_ERROR',
        503 => 'INFERENCE_UNAVAILABLE',
    ];

    /**
     * Safe, user-facing default messages. Internal exception messages are never echoed.
     *
     * @var array<string, string>
     */
    private const array CODE_TO_MESSAGE = [
        'BAD_REQUEST' => 'Permintaan tidak valid.',
        'UNAUTHENTICATED' => 'Unauthenticated.',
        'FORBIDDEN' => 'Akses ditolak.',
        'NOT_FOUND' => 'Sumber daya tidak ditemukan.',
        'CONFLICT' => 'Konflik status permintaan.',
        'PAYLOAD_TOO_LARGE' => 'Ukuran file melebihi batas 20 MB.',
        'UNSUPPORTED_MEDIA_TYPE' => 'Format file tidak didukung. Hanya PDF yang diterima.',
        'VALIDATION_ERROR' => 'The given data was invalid.',
        'RATE_LIMITED' => 'Too many attempts. Please try again later.',
        'SERVER_ERROR' => 'Terjadi kesalahan pada server.',
        'INFERENCE_UNAVAILABLE' => 'Layanan analisis tidak tersedia. Coba lagi nanti.',
    ];

    /**
     * Build the canonical error response.
     *
     * @param  array<string, mixed>|null  $details
     * @param  array<string, string>  $headers
     */
    public static function response(
        string $code,
        string $message,
        int $status,
        ?array $details = null,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'error' => new ErrorResponseData(
                code: $code,
                message: $message,
                details: $details ?? new Optional,
            ),
        ], $status, $headers);
    }

    /**
     * Build the canonical error response from an HTTP status, normalized to a
     * status the contract actually defines.
     *
     * @param  array<string, string>  $headers
     */
    public static function statusResponse(int $status, ?string $message = null, array $headers = []): JsonResponse
    {
        $status = self::normalizeStatus($status);

        return self::response(
            code: self::codeForStatus($status),
            message: $message ?? self::messageForStatus($status),
            status: $status,
            headers: $headers,
        );
    }

    /**
     * Map an HTTP status to the canonical error code.
     *
     * Unknown 4xx statuses become `BAD_REQUEST`; unknown 5xx statuses become `SERVER_ERROR`.
     */
    public static function codeForStatus(int $status): string
    {
        return self::STATUS_TO_CODE[$status] ?? ($status >= 500 ? 'SERVER_ERROR' : 'BAD_REQUEST');
    }

    /**
     * Normalize an unknown HTTP status to the closest canonical status.
     */
    public static function normalizeStatus(int $status): int
    {
        return array_key_exists($status, self::STATUS_TO_CODE)
            ? $status
            : ($status >= 500 ? 500 : 400);
    }

    /**
     * The safe default message for an HTTP status.
     */
    public static function messageForStatus(int $status): string
    {
        return self::CODE_TO_MESSAGE[self::codeForStatus(self::normalizeStatus($status))];
    }
}
