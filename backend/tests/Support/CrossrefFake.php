<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fixture-driven `Http::fake` stubs for the Crossref REST API.
 *
 * Routes are registered by DOI (for `/works/{doi}`) and by a query substring
 * (for `/works?query.bibliographic=...`). Unregistered DOIs return `404`; an
 * unmatched search returns `search-empty`. This lets a test control exactly
 * which references resolve without a real network call.
 */
final class CrossrefFake
{
    /**
     * @param  array<string, array<string, mixed>>  $doiRoutes  DOI => payload
     * @param  array<string, array<string, mixed>>  $searchRoutes  query substring => payload
     */
    public static function install(array $doiRoutes = [], array $searchRoutes = []): void
    {
        Http::fake([
            self::baseUrl().'/*' => function (Request $request) use ($doiRoutes, $searchRoutes) {
                $query = urldecode((string) parse_url($request->url(), PHP_URL_QUERY));

                if (! str_contains($query, 'query.bibliographic')) {
                    $doi = rawurldecode((string) substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/works/')));

                    return isset($doiRoutes[$doi])
                        ? Http::response($doiRoutes[$doi], 200)
                        : Http::response('', 404);
                }

                foreach ($searchRoutes as $needle => $payload) {
                    if (str_contains($query, (string) $needle)) {
                        return Http::response($payload, 200);
                    }
                }

                return Http::response(Fixtures::json('crossref/search-empty'), 200);
            },
        ]);
    }

    /**
     * The three product cases from the extraction contract fixture.
     */
    public static function forExtractFixture(): void
    {
        self::install(
            doiRoutes: [
                '10.1038/nature14539' => Fixtures::json('crossref/doi-found'),
            ],
            searchRoutes: [
                'Sistem deteksi plagiarisme' => Fixtures::json('crossref/search-multiple'),
            ],
        );
    }

    public static function unavailable(): void
    {
        Http::fake([
            self::baseUrl().'/*' => Http::response([
                'status' => 'failed',
                'message' => 'Service unavailable.',
            ], 503),
        ]);
    }

    private static function baseUrl(): string
    {
        return rtrim((string) config('services.crossref.base_url'), '/');
    }
}
