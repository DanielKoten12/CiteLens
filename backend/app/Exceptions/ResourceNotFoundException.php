<?php

namespace App\Exceptions;

/**
 * Raised when a document-owned resource does not exist or is not owned by the
 * authenticated user.
 *
 * Foreign resources deliberately return `404 NOT_FOUND` with the same message as
 * missing ones, so the response never discloses existence (`docs/SECURITY.md` §2.1).
 */
final class ResourceNotFoundException extends ApiException
{
    public static function document(): self
    {
        return new self('Dokumen tidak ditemukan.');
    }

    public static function reference(): self
    {
        return new self('Referensi tidak ditemukan.');
    }

    public static function citation(): self
    {
        return new self('Sitasi tidak ditemukan.');
    }

    public static function report(): self
    {
        return new self('Laporan tidak ditemukan.');
    }

    public function code(): string
    {
        return 'NOT_FOUND';
    }

    public function status(): int
    {
        return 404;
    }
}
