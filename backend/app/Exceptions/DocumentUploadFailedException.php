<?php

namespace App\Exceptions;

use Throwable;

/**
 * Raised when an uploaded document cannot be persisted.
 *
 * Rendering lives in {@see ApiException} so services and controllers stay free of
 * transport concerns and the API error envelope is produced in one place.
 */
class DocumentUploadFailedException extends ApiException
{
    /**
     * The stored file could not be written to disk.
     */
    public static function storageFailed(): self
    {
        return new self('Dokumen gagal disimpan. Silakan coba lagi.');
    }

    /**
     * The document/file records could not be persisted.
     */
    public static function persistenceFailed(Throwable $previous): self
    {
        return new self(
            'Dokumen berhasil diunggah tetapi gagal dicatat. Silakan coba lagi.',
            previous: $previous,
        );
    }

    public function code(): string
    {
        return 'SERVER_ERROR';
    }

    public function status(): int
    {
        return 500;
    }
}
