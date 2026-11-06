<?php

use App\Enums\MetadataSource;
use App\Exceptions\OpenAlexUnavailableException;
use App\Services\Metadata\ReferenceQuery;
use App\Services\OpenAlex\OpenAlexClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.openalex.enabled' => true,
        'services.openalex.api_key' => 'test-key',
        'services.openalex.mailto' => 'copy@example.org',
        'services.openalex.base_url' => 'https://api.openalex.org',
        'services.openalex.timeout' => 2,
        'services.openalex.connect_timeout' => 1,
        'services.openalex.retries' => 1,
        'services.openalex.retry_backoff_ms' => 0,
        'services.openalex.max_retry_after_seconds' => 5,
        'services.openalex.per_page' => 5,
        'services.openalex.min_interval_ms' => 0,
        'services.openalex.cache_ttl' => 60,
        'services.openalex.negative_cache_ttl' => 60,
        'services.openalex.max_response_bytes' => 2097152,
        'services.openalex.user_agent' => 'TestAgent/1.0 (mailto:copy@example.org)',
    ]);

    $this->client = app(OpenAlexClient::class);
});

function oaQuery(): ReferenceQuery
{
    return new ReferenceQuery(
        bibliographic: 'Deep learning LeCun 2015',
        author: 'LeCun',
        year: 2015,
        title: 'Deep-Learning',
    );
}

function oaFakeSearch(?string $fixture = 'openalex/search-results', int $status = 200, array $headers = [], string $rawBody = ''): void
{
    Http::fake([
        'api.openalex.org/*' => Http::response(
            $fixture === null ? $rawBody : Fixtures::json($fixture),
            $status,
            $headers,
        ),
    ]);
}

it('sends a title search filter with bearer auth and mailto, never the key in the url', function () {
    oaFakeSearch();

    $works = $this->client->search(oaQuery());

    expect($works)->toHaveCount(2)
        ->and($works[0]->source)->toBe(MetadataSource::OpenAlex);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/works')
            && ($query['filter'] ?? null) === 'title.search:deep learning'
            && ! array_key_exists('search', $query)
            && (int) ($query['per-page'] ?? 0) === 5
            && ($query['mailto'] ?? null) === 'copy@example.org'
            && str_contains((string) ($query['select'] ?? ''), 'primary_location')
            && ($request->header('Authorization')[0] ?? null) === 'Bearer test-key'
            && ! str_contains($request->url(), 'test-key');
    });
});

it('serves repeated searches from the cache', function () {
    oaFakeSearch();

    $this->client->search(oaQuery());
    $this->client->search(oaQuery());

    Http::assertSentCount(1);
});

it('caches an empty search with the negative ttl', function () {
    oaFakeSearch('openalex/search-empty');

    expect($this->client->search(oaQuery()))->toBe([])
        ->and($this->client->search(oaQuery()))->toBe([]);

    Http::assertSentCount(1);
});

it('is disabled without an api key and sends nothing', function () {
    config(['services.openalex.api_key' => null]);

    $client = app(OpenAlexClient::class);

    expect($client->enabled())->toBeFalse()
        ->and($client->search(oaQuery()))->toBe([])
        ->and($client->resolve('10.1038/nature14539'))->toBeNull();

    Http::assertNothingSent();
});

it('retries once on a short retry-after', function () {
    $calls = 0;

    Http::fake([
        'api.openalex.org/*' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response('', 429, ['Retry-After' => '1'])
                : Http::response(Fixtures::json('openalex/search-results'));
        },
    ]);

    expect($this->client->search(oaQuery()))->toHaveCount(2);
    Http::assertSentCount(2);
});

it('throws instead of sleeping through a long retry-after', function () {
    oaFakeSearch(null, 429, ['Retry-After' => '60']);

    expect(fn () => $this->client->search(oaQuery()))
        ->toThrow(OpenAlexUnavailableException::class);
});

it('retries server errors and maps connection failures to the source unavailable', function () {
    oaFakeSearch(null, 503);

    expect(fn () => $this->client->search(oaQuery()))
        ->toThrow(OpenAlexUnavailableException::class);

    Http::assertSentCount(2);

    Http::fake([
        'api.openalex.org/*' => fn () => throw new ConnectionException('Connection refused.'),
    ]);

    expect(fn () => $this->client->search(oaQuery()))
        ->toThrow(OpenAlexUnavailableException::class);
});

it('rejects a non-json response', function () {
    oaFakeSearch(null, 200, ['Content-Type' => 'text/plain']);

    expect(fn () => $this->client->search(oaQuery()))
        ->toThrow(OpenAlexUnavailableException::class);
});

it('rejects an oversized response', function () {
    oaFakeSearch();
    config(['services.openalex.max_response_bytes' => 10]);

    expect(fn () => $this->client->search(oaQuery()))
        ->toThrow(OpenAlexUnavailableException::class);
});

it('rejects a payload missing the documented results array', function () {
    oaFakeSearch('openalex/search-malformed');

    expect(fn () => $this->client->search(oaQuery()))
        ->toThrow(OpenAlexUnavailableException::class);
});

it('treats a bad query as a per-reference miss, not an outage', function () {
    oaFakeSearch(null, 400);

    expect($this->client->search(oaQuery()))->toBe([])
        ->and($this->client->resolve('10.1038/nature14539'))->toBeNull();
});

it('reports rejected credentials loudly', function () {
    oaFakeSearch('openalex/search-unauthorized', 401);

    expect(fn () => $this->client->search(oaQuery()))
        ->toThrow(OpenAlexUnavailableException::class);
});

it('resolves a doi through the free single-entity endpoint', function () {
    Http::fake([
        'api.openalex.org/*' => Http::response(Fixtures::json('openalex/doi-found')),
    ]);

    $work = $this->client->resolve('10.1038/nature14539');

    expect($work?->title)->toBe('Deep learning')
        ->and($work?->doi)->toBe('10.1038/nature14539')
        ->and($work?->source)->toBe(MetadataSource::OpenAlex);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/works/doi:10.1038/nature14539'));
});

it('returns null for a doi miss and caches it', function () {
    oaFakeSearch(null, 404);

    expect($this->client->resolve('10.1234/missing'))->toBeNull()
        ->and($this->client->resolve('10.1234/missing'))->toBeNull();

    Http::assertSentCount(1);
});

it('reports a doi lookup outage as the source unavailable', function () {
    Http::fake([
        'api.openalex.org/*' => fn () => throw new ConnectionException('Connection refused.'),
    ]);

    expect(fn () => $this->client->resolve('10.1234/down'))
        ->toThrow(OpenAlexUnavailableException::class);
});
