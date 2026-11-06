<?php

use App\Enums\MetadataSource;
use App\Exceptions\SemanticScholarUnavailableException;
use App\Services\Metadata\ReferenceQuery;
use App\Services\SemanticScholar\SemanticScholarClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.semantic_scholar.enabled' => true,
        'services.semantic_scholar.api_key' => 'test-key',
        'services.semantic_scholar.base_url' => 'https://api.semanticscholar.org',
        'services.semantic_scholar.timeout' => 2,
        'services.semantic_scholar.connect_timeout' => 1,
        'services.semantic_scholar.retries' => 1,
        'services.semantic_scholar.retry_backoff_ms' => 0,
        'services.semantic_scholar.max_retry_after_seconds' => 5,
        'services.semantic_scholar.limit' => 5,
        'services.semantic_scholar.min_interval_ms' => 0,
        'services.semantic_scholar.cache_ttl' => 60,
        'services.semantic_scholar.negative_cache_ttl' => 60,
        'services.semantic_scholar.max_response_bytes' => 2097152,
        'services.semantic_scholar.user_agent' => 'TestAgent/1.0 (+mailto:test@example.com)',
    ]);

    $this->client = app(SemanticScholarClient::class);
});

function s2Query(): ReferenceQuery
{
    return new ReferenceQuery(
        bibliographic: 'Deep learning LeCun 2015',
        author: 'LeCun',
        year: 2015,
        title: 'Deep-Learning',
    );
}

function s2FakeSearch(?string $fixture = 'semantic_scholar/search-results', int $status = 200, array $headers = [], string $rawBody = ''): void
{
    Http::fake([
        'api.semanticscholar.org/*' => Http::response(
            $fixture === null ? $rawBody : Fixtures::json($fixture),
            $status,
            $headers,
        ),
    ]);
}

it('sends title-only search params with the key header and never in the url', function () {
    s2FakeSearch();

    $works = $this->client->search(s2Query());

    expect($works)->toHaveCount(2)
        ->and($works[0]->source)->toBe(MetadataSource::SemanticScholar);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/graph/v1/paper/search')
            && ($query['query'] ?? null) === 'deep learning'
            && (int) ($query['limit'] ?? 0) === 5
            && str_contains((string) ($query['fields'] ?? ''), 'externalIds')
            && ($request->header('x-api-key')[0] ?? null) === 'test-key'
            && ! str_contains($request->url(), 'test-key');
    });
});

it('serves repeated searches from the cache', function () {
    s2FakeSearch();

    $this->client->search(s2Query());
    $this->client->search(s2Query());

    Http::assertSentCount(1);
});

it('caches an empty search with the negative ttl', function () {
    s2FakeSearch('semantic_scholar/search-empty');

    expect($this->client->search(s2Query()))->toBe([])
        ->and($this->client->search(s2Query()))->toBe([]);

    Http::assertSentCount(1);
});

it('is disabled without an api key and sends nothing', function () {
    config(['services.semantic_scholar.api_key' => null]);

    $client = app(SemanticScholarClient::class);

    expect($client->enabled())->toBeFalse()
        ->and($client->search(s2Query()))->toBe([])
        ->and($client->resolve('10.1038/nature14539'))->toBeNull();

    Http::assertNothingSent();
});

it('retries once on a short retry-after', function () {
    $calls = 0;

    Http::fake([
        'api.semanticscholar.org/*' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response('', 429, ['Retry-After' => '0'])
                : Http::response(Fixtures::json('semantic_scholar/search-results'));
        },
    ]);

    expect($this->client->search(s2Query()))->toHaveCount(2);

    Http::assertSentCount(2);
});

it('throws instead of sleeping through a long retry-after', function () {
    s2FakeSearch(null, 429, ['Retry-After' => '60']);

    expect(fn () => $this->client->search(s2Query()))
        ->toThrow(SemanticScholarUnavailableException::class);

    Http::assertSentCount(1);
});

it('retries server errors and then reports the source unavailable', function () {
    s2FakeSearch(null, 503);

    expect(fn () => $this->client->search(s2Query()))
        ->toThrow(SemanticScholarUnavailableException::class);

    Http::assertSentCount(2);
});

it('reports connection failures as the source unavailable', function () {
    Http::fake([
        'api.semanticscholar.org/*' => fn () => throw new ConnectionException('Connection refused.'),
    ]);

    expect(fn () => $this->client->search(s2Query()))
        ->toThrow(SemanticScholarUnavailableException::class);
});

it('rejects a non-json response', function () {
    s2FakeSearch(null, 200, ['Content-Type' => 'text/plain']);

    expect(fn () => $this->client->search(s2Query()))
        ->toThrow(SemanticScholarUnavailableException::class);
});

it('rejects an oversized response', function () {
    s2FakeSearch('semantic_scholar/search-results');

    config(['services.semantic_scholar.max_response_bytes' => 10]);

    expect(fn () => $this->client->search(s2Query()))
        ->toThrow(SemanticScholarUnavailableException::class);
});

it('rejects a payload missing the documented data array', function () {
    s2FakeSearch('semantic_scholar/search-malformed');

    expect(fn () => $this->client->search(s2Query()))
        ->toThrow(SemanticScholarUnavailableException::class);
});

it('treats a bad query as a per-reference miss, not an outage', function () {
    s2FakeSearch(null, 400);

    expect($this->client->search(s2Query()))->toBe([])
        ->and($this->client->resolve('10.1038/nature14539'))->toBeNull();
});

it('reports rejected credentials loudly', function () {
    s2FakeSearch('semantic_scholar/search-unauthorized', 401);

    expect(fn () => $this->client->search(s2Query()))
        ->toThrow(SemanticScholarUnavailableException::class);
});

it('resolves a doi through the single-paper endpoint', function () {
    Http::fake([
        'api.semanticscholar.org/*' => Http::response(Fixtures::json('semantic_scholar/doi-found')),
    ]);

    $work = $this->client->resolve('10.1038/nature14539');

    expect($work?->title)->toBe('Deep learning')
        ->and($work?->doi)->toBe('10.1038/nature14539')
        ->and($work?->source)->toBe(MetadataSource::SemanticScholar);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/graph/v1/paper/DOI:10.1038/nature14539'));
});

it('returns null for a doi miss and caches it', function () {
    s2FakeSearch(null, 404);

    expect($this->client->resolve('10.1234/missing'))->toBeNull()
        ->and($this->client->resolve('10.1234/missing'))->toBeNull();

    Http::assertSentCount(1);
});

it('reports a doi lookup outage as the source unavailable', function () {
    Http::fake([
        'api.semanticscholar.org/*' => fn () => throw new ConnectionException('Connection refused.'),
    ]);

    expect(fn () => $this->client->resolve('10.1234/down'))
        ->toThrow(SemanticScholarUnavailableException::class);
});
