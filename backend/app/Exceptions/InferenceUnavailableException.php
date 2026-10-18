<?php

namespace App\Exceptions;

use Throwable;

/**
 * Raised when the internal inference service (GROBID/SBERT) is unreachable or
 * analysis cannot complete because of it.
 *
 * Maps to `503 INFERENCE_UNAVAILABLE` with a safe message; raw details go to the
 * log only (`docs/SECURITY.md` §3).
 */
final class InferenceUnavailableException extends ApiException
{
    public static function serviceUnavailable(?Throwable $previous = null): self
    {
        return new self('Layanan analisis tidak tersedia. Coba lagi nanti.', previous: $previous);
    }

    public function code(): string
    {
        return 'INFERENCE_UNAVAILABLE';
    }

    public function status(): int
    {
        return 503;
    }
}
