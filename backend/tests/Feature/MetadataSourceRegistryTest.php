<?php

use App\Enums\MetadataSource;
use App\Services\Crossref\CrossrefClient;
use App\Services\Garuda\GarudaClient;
use App\Services\Metadata\MetadataSourceRegistry;
use App\Services\OpenAlex\OpenAlexClient;
use App\Services\SemanticScholar\SemanticScholarClient;
use Illuminate\Support\Facades\Cache;
use Tests\Support\MetadataSourceConfig;

beforeEach(function (): void {
    Cache::flush();
    MetadataSourceConfig::apply();
});

it('resolves the configured ordered source lists', function () {
    $sources = app(MetadataSourceRegistry::class)->resolve();

    expect(array_map(fn ($source) => $source->source(), $sources->primary))
        ->toBe([MetadataSource::Crossref])
        ->and(array_map(fn ($source) => $source->source(), $sources->fallback))
        ->toBe([MetadataSource::Garuda, MetadataSource::SemanticScholar, MetadataSource::OpenAlex])
        ->and(array_map(fn ($source) => $source->source(), $sources->doiResolvers))
        ->toBe([MetadataSource::Crossref, MetadataSource::SemanticScholar, MetadataSource::OpenAlex])
        ->and($sources->isFallback(MetadataSource::Garuda))->toBeTrue()
        ->and($sources->isFallback(MetadataSource::Crossref))->toBeFalse();
});

it('rejects a class that does not implement the required contract', function () {
    config(['analysis.metadata.primary_sources' => [stdClass::class]]);

    expect(fn () => app(MetadataSourceRegistry::class)->resolve())
        ->toThrow(LogicException::class);
});

it('rejects an unknown class', function () {
    config(['analysis.metadata.primary_sources' => ['App\\Services\\DoesNotExist']]);

    expect(fn () => app(MetadataSourceRegistry::class)->resolve())
        ->toThrow(LogicException::class);
});

it('rejects a duplicated source within a list', function () {
    config(['analysis.metadata.primary_sources' => [CrossrefClient::class, CrossrefClient::class]]);

    expect(fn () => app(MetadataSourceRegistry::class)->resolve())
        ->toThrow(LogicException::class, 'appears more than once');
});

it('rejects a source configured as both primary and fallback', function () {
    config(['analysis.metadata.fallback_sources' => [GarudaClient::class, CrossrefClient::class]]);

    expect(fn () => app(MetadataSourceRegistry::class)->resolve())
        ->toThrow(LogicException::class, 'both primary and fallback');
});

it('rejects an unknown failure policy before running the chain', function () {
    config(['analysis.metadata.failure_policies.garuda' => 'sometimes']);

    expect(fn () => app(MetadataSourceRegistry::class)->resolve())
        ->toThrow(LogicException::class);
});

it('keeps every source class resolvable', function () {
    expect(app(CrossrefClient::class))->toBeInstanceOf(CrossrefClient::class)
        ->and(app(GarudaClient::class))->toBeInstanceOf(GarudaClient::class)
        ->and(app(SemanticScholarClient::class))->toBeInstanceOf(SemanticScholarClient::class)
        ->and(app(OpenAlexClient::class))->toBeInstanceOf(OpenAlexClient::class);
});
