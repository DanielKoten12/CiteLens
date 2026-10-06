<?php

use App\Http\Responses\ApiError;

/**
 * Pins the single HTTP status → canonical code mapping (`docs/API_SPEC.md` §2.5).
 */
it('maps every canonical status to its error code', function () {
    expect([
        400 => ApiError::codeForStatus(400),
        401 => ApiError::codeForStatus(401),
        403 => ApiError::codeForStatus(403),
        404 => ApiError::codeForStatus(404),
        409 => ApiError::codeForStatus(409),
        413 => ApiError::codeForStatus(413),
        415 => ApiError::codeForStatus(415),
        422 => ApiError::codeForStatus(422),
        429 => ApiError::codeForStatus(429),
        500 => ApiError::codeForStatus(500),
        503 => ApiError::codeForStatus(503),
    ])->toBe([
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
    ]);
});

it('falls back to BAD_REQUEST for unknown 4xx and SERVER_ERROR for unknown 5xx', function () {
    expect(ApiError::codeForStatus(418))->toBe('BAD_REQUEST')
        ->and(ApiError::codeForStatus(451))->toBe('BAD_REQUEST')
        ->and(ApiError::codeForStatus(599))->toBe('SERVER_ERROR')
        ->and(ApiError::codeForStatus(507))->toBe('SERVER_ERROR');
});

it('normalizes unknown statuses to a canonical status', function () {
    expect(ApiError::normalizeStatus(418))->toBe(400)
        ->and(ApiError::normalizeStatus(451))->toBe(400)
        ->and(ApiError::normalizeStatus(599))->toBe(500)
        ->and(ApiError::normalizeStatus(507))->toBe(500)
        ->and(ApiError::normalizeStatus(404))->toBe(404)
        ->and(ApiError::normalizeStatus(503))->toBe(503);
});

it('resolves a safe default message per status', function () {
    expect(ApiError::messageForStatus(404))->toBe('Sumber daya tidak ditemukan.')
        ->and(ApiError::messageForStatus(500))->toBe('Terjadi kesalahan pada server.')
        ->and(ApiError::messageForStatus(418))->toBe('Permintaan tidak valid.')
        ->and(ApiError::messageForStatus(599))->toBe('Terjadi kesalahan pada server.');
});
