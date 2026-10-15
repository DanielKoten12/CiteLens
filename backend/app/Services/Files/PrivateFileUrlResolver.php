<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The single rule for turning a private-disk object into a URL.
 *
 * Prefers a temporary (signed) URL when the disk supports it and falls back to
 * the plain URL otherwise; returns null when no URL can be built (missing disk,
 * unsupported driver) instead of throwing, so API responses stay well-formed.
 *
 * `disk` comes from the `files` row so objects on `reports.disk` resolve
 * correctly while document uploads stay on the application default (D-06-03).
 */
final class PrivateFileUrlResolver
{
    /**
     * A time-limited URL for a private-disk object, or null when it cannot be built.
     */
    public function resolve(?string $path, ?string $disk = null): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            $disk = Storage::disk($disk ?? (string) config('filesystems.default'));

            return $disk->providesTemporaryUrls()
                ? $disk->temporaryUrl($path, now()->addHour())
                : $disk->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
