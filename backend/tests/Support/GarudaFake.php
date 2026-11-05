<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Fixture-driven `Http::fake` stubs for the Garuda search page.
 *
 * `Http::fake()` appends stubs, so installing Crossref fakes afterwards does
 * not remove these (URLs differ).
 */
final class GarudaFake
{
    public static function install(string $fixture, int $status = 200): void
    {
        Http::fake([
            self::baseUrl().'/*' => Http::response(
                Fixtures::text('garuda/'.$fixture),
                $status,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);
    }

    public static function empty(): void
    {
        self::install('search-empty.html');
    }

    public static function results(): void
    {
        self::install('search.html');
    }

    public static function malformed(): void
    {
        self::install('search-malformed.html');
    }

    public static function unavailable(): void
    {
        self::install('search-empty.html', 500);
    }

    private static function baseUrl(): string
    {
        return rtrim((string) config('services.garuda.base_url'), '/');
    }
}
