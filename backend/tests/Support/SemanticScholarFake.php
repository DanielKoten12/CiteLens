<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fixture-driven `Http::fake` stubs for the Semantic Scholar Graph API.
 *
 * Search routes answer with one recorded payload (the source is queried with a
 * normalized title, so a single recorded response is enough for chain tests);
 * DOI routes are registered by normalized DOI and default to `404`. This lets a
 * test decide exactly which references/DOIs resolve without a real API key.
 */
final class SemanticScholarFake
{
    /**
     * @param  array<string, string>  $doiFixtures  normalized DOI => fixture path
     * @param  array<string, string>  $headers
     */
    public static function install(
        string $searchFixture = 'semantic_scholar/search-empty',
        array $doiFixtures = [],
        int $searchStatus = 200,
        int $doiStatus = 200,
        array $headers = ['Content-Type' => 'application/json'],
    ): void {
        Http::fake([
            self::baseUrl().'/*' => function (Request $request) use ($searchFixture, $doiFixtures, $searchStatus, $doiStatus, $headers) {
                $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

                if (str_contains($path, '/paper/DOI:')) {
                    if ($doiStatus >= 400) {
                        return Http::response('', $doiStatus, $headers);
                    }

                    $doi = substr($path, strpos($path, 'DOI:') + strlen('DOI:'));

                    return isset($doiFixtures[$doi])
                        ? Http::response(Fixtures::json($doiFixtures[$doi]), 200, $headers)
                        : Http::response('', 404, $headers);
                }

                if ($searchStatus >= 400) {
                    return Http::response('', $searchStatus, $headers);
                }

                return Http::response(Fixtures::json($searchFixture), 200, $headers);
            },
        ]);
    }

    public static function results(): void
    {
        self::install('semantic_scholar/search-results');
    }

    public static function empty(): void
    {
        self::install();
    }

    public static function malformed(): void
    {
        self::install('semantic_scholar/search-malformed');
    }

    public static function unauthorized(): void
    {
        self::install(searchStatus: 401, doiStatus: 401);
    }

    public static function unavailable(int $status = 503): void
    {
        self::install(searchStatus: $status, doiStatus: $status);
    }

    /**
     * @param  array<string, string>  $doiFixtures
     */
    public static function doiFound(array $doiFixtures = []): void
    {
        self::install(
            searchFixture: 'semantic_scholar/search-empty',
            doiFixtures: $doiFixtures === []
                ? ['10.1234/not-in-crossref' => 'semantic_scholar/doi-found']
                : $doiFixtures,
        );
    }

    public static function doiMissing(): void
    {
        self::install();
    }

    private static function baseUrl(): string
    {
        return rtrim((string) config('services.semantic_scholar.base_url'), '/');
    }
}
