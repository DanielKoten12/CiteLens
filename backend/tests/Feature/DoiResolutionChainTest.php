<?php

use App\Enums\MetadataSource;
use App\Exceptions\CrossrefUnavailableException;
use App\Services\Metadata\DoiLookup;
use App\Services\Metadata\DoiResolutionChain;
use App\Services\Metadata\DoiResolutionResult;
use App\Services\Metadata\SourceChainState;
use App\Services\Metadata\Support\SourceCircuitBreaker;
use App\Services\Scoring\MatchReasonBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\CrossrefFake;
use Tests\Support\Fixtures;
use Tests\Support\OpenAlexFake;
use Tests\Support\SemanticScholarFake;

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.crossref.mailto' => 'test@example.com',
        'services.crossref.base_url' => 'https://api.crossref.org',
        'services.crossref.retries' => 1,
        'services.crossref.retry_backoff_ms' => 0,
        'services.crossref.cache_ttl' => 60,
        'services.semantic_scholar.enabled' => true,
        'services.semantic_scholar.api_key' => 'test-key',
        'services.semantic_scholar.base_url' => 'https://api.semanticscholar.org',
        'services.semantic_scholar.retries' => 0,
        'services.semantic_scholar.retry_backoff_ms' => 0,
        'services.semantic_scholar.min_interval_ms' => 0,
        'services.semantic_scholar.cache_ttl' => 60,
        'services.semantic_scholar.negative_cache_ttl' => 60,
        'services.semantic_scholar.max_requests_per_document' => 200,
        'services.openalex.enabled' => true,
        'services.openalex.api_key' => 'test-key',
        'services.openalex.mailto' => 'test@example.com',
        'services.openalex.base_url' => 'https://api.openalex.org',
        'services.openalex.retries' => 0,
        'services.openalex.retry_backoff_ms' => 0,
        'services.openalex.min_interval_ms' => 0,
        'services.openalex.cache_ttl' => 60,
        'services.openalex.negative_cache_ttl' => 60,
        'services.openalex.max_requests_per_document' => 200,
        'analysis.metadata.failure_policies' => [
            'crossref' => 'fatal',
            'garuda' => 'fatal',
            'semantic_scholar' => 'degrade',
            'openalex' => 'degrade',
        ],
        'analysis.metadata.fallback.max_requests_per_document' => 300,
        'analysis.metadata.fallback.max_seconds' => 300,
        'analysis.metadata.circuit_breaker.enabled' => true,
        'analysis.metadata.circuit_breaker.failure_threshold' => 3,
        'analysis.metadata.circuit_breaker.cooldown_seconds' => 120,
    ]);
});

function doiState(): SourceChainState
{
    return new SourceChainState(documentId: 'doc-1', fallbackBudget: 300, fallbackSeconds: 300);
}

function resolveDoi(string $doi, ?SourceChainState $state = null): DoiResolutionResult
{
    return app(DoiResolutionChain::class)->resolve($doi, $state ?? doiState());
}

it('stops at crossref when it answers', function () {
    CrossrefFake::install(doiRoutes: ['10.1038/nature14539' => Fixtures::json('crossref/doi-found')]);
    SemanticScholarFake::doiFound(['10.1038/nature14539' => 'semantic_scholar/doi-found']);
    OpenAlexFake::doiFound(['10.1038/nature14539' => 'openalex/doi-found']);

    $result = resolveDoi('10.1038/nature14539');

    expect($result->lookup)->toBe(DoiLookup::Resolved)
        ->and($result->source)->toBe(MetadataSource::Crossref)
        ->and($result->work?->title)->toBe('Deep learning');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'semanticscholar.org')
        || str_contains($request->url(), 'openalex.org'));
});

it('falls back to semantic scholar after a crossref miss', function () {
    CrossrefFake::install();
    SemanticScholarFake::install(doiFixtures: ['10.1234/s2-only' => 'semantic_scholar/doi-found']);
    OpenAlexFake::doiFound(['10.1234/s2-only' => 'openalex/doi-found']);

    $result = resolveDoi('10.1234/s2-only');

    expect($result->lookup)->toBe(DoiLookup::Resolved)
        ->and($result->source)->toBe(MetadataSource::SemanticScholar)
        // The DOI endpoint is keyed by DOI: the requested DOI is proof of resolution.
        ->and($result->work?->doi)->toBe('10.1234/s2-only');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'openalex.org'));
});

it('falls back to openalex after crossref and semantic scholar misses', function () {
    CrossrefFake::install();
    SemanticScholarFake::empty();
    OpenAlexFake::install(doiFixtures: ['10.1234/oa-only' => 'openalex/doi-found']);

    $result = resolveDoi('10.1234/oa-only');

    expect($result->lookup)->toBe(DoiLookup::Resolved)
        ->and($result->source)->toBe(MetadataSource::OpenAlex)
        ->and($result->work?->doi)->toBe('10.1234/oa-only');
});

it('reports a definitive miss only when every enabled resolver answered', function () {
    CrossrefFake::install();
    SemanticScholarFake::empty();
    OpenAlexFake::empty();

    $result = resolveDoi('10.1234/missing');

    expect($result->lookup)->toBe(DoiLookup::NotFound)
        ->and($result->work)->toBeNull()
        ->and($result->pendingReason)->toBeNull();
});

it('reports unknown when an enabled resolver could not answer and none resolved', function () {
    CrossrefFake::install();
    SemanticScholarFake::unavailable(503);
    OpenAlexFake::empty();

    $result = resolveDoi('10.1234/unchecked');

    expect($result->lookup)->toBe(DoiLookup::Unknown)
        ->and($result->pendingReason)->toBe(MatchReasonBuilder::DOI_UNKNOWN);
});

it('treats a disabled resolver as absent, not as an outage', function () {
    config([
        'services.semantic_scholar.api_key' => null,
        'services.openalex.api_key' => null,
    ]);

    CrossrefFake::install();
    SemanticScholarFake::doiFound();
    OpenAlexFake::doiFound();

    $result = resolveDoi('10.1234/missing');

    expect($result->lookup)->toBe(DoiLookup::NotFound);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'semanticscholar.org')
        || str_contains($request->url(), 'openalex.org'));
});

it('rethrows the first failure of a fatal resolver', function () {
    Http::fake(['api.crossref.org/*' => Http::response('', 503)]);
    SemanticScholarFake::doiFound();

    expect(fn () => resolveDoi('10.1038/nature14539'))
        ->toThrow(CrossrefUnavailableException::class);
});

it('continues through the chain when crossref is configured to degrade', function () {
    config(['analysis.metadata.failure_policies.crossref' => 'degrade']);

    Http::fake(['api.crossref.org/*' => Http::response('', 503)]);
    SemanticScholarFake::install(doiFixtures: ['10.1234/requested' => 'semantic_scholar/doi-found']);
    OpenAlexFake::empty();

    $result = resolveDoi('10.1234/requested');

    expect($result->lookup)->toBe(DoiLookup::Resolved)
        ->and($result->source)->toBe(MetadataSource::SemanticScholar);
});

it('skips a fallback resolver whose budget is exhausted and reports unknown', function () {
    config(['services.semantic_scholar.max_requests_per_document' => 1]);

    CrossrefFake::install();
    SemanticScholarFake::doiFound();
    OpenAlexFake::empty();

    $state = doiState();
    $state->recordLookup(MetadataSource::SemanticScholar);

    $result = resolveDoi('10.1234/missing', $state);

    expect($result->lookup)->toBe(DoiLookup::Unknown);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'semanticscholar.org'));
});

it('skips a fallback resolver whose circuit breaker is open', function () {
    config(['analysis.metadata.circuit_breaker.failure_threshold' => 1]);

    CrossrefFake::install();
    SemanticScholarFake::doiFound();
    OpenAlexFake::empty();

    app(SourceCircuitBreaker::class)->recordFailure(MetadataSource::SemanticScholar);

    $result = resolveDoi('10.1234/missing');

    expect($result->lookup)->toBe(DoiLookup::Unknown);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'semanticscholar.org'));
});
