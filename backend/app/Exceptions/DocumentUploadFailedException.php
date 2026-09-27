<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Raised when an uploaded document cannot be persisted.
 *
 * Rendering lives beside the exception so the controller/service stay free of
 * transport concerns and the API error envelope is produced in one place.
 */
class DocumentUploadFailedException extends RuntimeException
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

    /**
     * Render the exception using the canonical API error envelope.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'SERVER_ERROR',
                'message' => $this->getMessage(),
            ],
        ], 500);
    }
}
