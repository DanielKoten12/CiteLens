<?php

namespace App\Exceptions;

/**
 * Raised when an action is invalid for the current resource state.
 *
 * Maps to `409 CONFLICT` (`docs/API_SPEC.md` §2.5).
 */
final class StateConflictException extends ApiException
{
    public static function documentRetryNotAllowed(): self
    {
        return new self('Analisis hanya dapat diulang untuk dokumen yang gagal.');
    }

    public static function reportNotAllowed(): self
    {
        return new self('Laporan hanya dapat dibuat untuk dokumen yang selesai dianalisis.');
    }

    public function code(): string
    {
        return 'CONFLICT';
    }

    public function status(): int
    {
        return 409;
    }
}
