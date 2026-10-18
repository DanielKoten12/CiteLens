<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a file cannot be written to the private storage disk.
 *
 * Internal to the file lifecycle: controllers never render it; the owning
 * service maps it to the appropriate API/domain error (e.g. a failed document
 * upload or report generation).
 */
final class FileStorageException extends RuntimeException
{
    public static function diskWriteFailed(): self
    {
        return new self('The file could not be written to storage.');
    }
}
