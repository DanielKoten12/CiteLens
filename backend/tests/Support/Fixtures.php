<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Read-only loader for recorded external-service payloads in `tests/Fixtures/`.
 *
 * Pure PHP (no container access), so it is usable from Unit tests too.
 */
final class Fixtures
{
    private const BASE_PATH = __DIR__.'/../Fixtures';

    /**
     * Load a JSON fixture by path relative to `tests/Fixtures`, with or without
     * the `.json` extension.
     *
     * @return array<string, mixed>
     */
    public static function json(string $path): array
    {
        $file = self::BASE_PATH.'/'.ltrim($path, '/');

        if (! str_ends_with($file, '.json')) {
            $file .= '.json';
        }

        if (! is_file($file)) {
            throw new RuntimeException("Fixture not found: {$path}");
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("Fixture could not be read: {$path}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
