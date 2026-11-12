<?php

use App\Enums\MetadataSource;
use App\Services\Metadata\SourceChainPolicy;
use App\Services\Metadata\SourceChainState;
use App\Services\Metadata\WorkMetadata;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    config([
        'analysis.metadata.stop_policy' => 'plausible_title_with_doi',
        'analysis.metadata.failure_policies' => [
            'crossref' => 'fatal',
            'garuda' => 'fatal',
            'semantic_scholar' => 'degrade',
            'openalex' => 'degrade',
        ],
        'analysis.metadata.fallback.max_requests_per_document' => 300,
        'analysis.metadata.fallback.max_seconds' => 300,
        'scoring.fallback.title_threshold' => 0.85,
        'services.garuda.max_requests_per_document' => 500,
        'services.semantic_scholar.max_requests_per_document' => 200,
        'services.openalex.max_requests_per_document' => 200,
    ]);
});

function policyState(int $fallbackBudget = 300, int $fallbackSeconds = 300, float $startedAgo = 0.0): SourceChainState
{
    return new SourceChainState(
        documentId: 'doc-1',
        fallbackBudget: $fallbackBudget,
        fallbackSeconds: $fallbackSeconds,
        startedAt: hrtime(true) / 1_000_000_000 - $startedAgo,
    );
}

it('gates on the minimum title length', function () {
    $policy = app(SourceChainPolicy::class);

    expect($policy->shouldGate('Short'))->toBeFalse()
        ->and($policy->shouldGate('  Seven  '))->toBeFalse()
        ->and($policy->shouldGate('Deep learning'))->toBeTrue()
        ->and($policy->shouldGate(null))->toBeFalse();
});

it('requires a doi only under the plausible-title-with-doi policy', function () {
    $withoutDoi = [new WorkMetadata(doi: null, title: 'Deep learning')];
    $withDoi = [new WorkMetadata(doi: '10.1038/nature14539', title: 'Deep learning')];

    $policy = app(SourceChainPolicy::class);

    expect($policy->isSatisfied('Deep learning', $withDoi))->toBeTrue()
        ->and($policy->isSatisfied('Deep learning', $withoutDoi))->toBeFalse();

    config(['analysis.metadata.stop_policy' => 'plausible_title']);

    $plausible = app(SourceChainPolicy::class);

    expect($plausible->isSatisfied('Deep learning', $withoutDoi))->toBeTrue()
        ->and($plausible->isSatisfied('Deep learning', []))->toBeFalse();
});

it('reads env-overridable failure policies with conservative defaults', function () {
    $policy = app(SourceChainPolicy::class);

    expect($policy->failurePolicy(MetadataSource::Crossref))->toBe('fatal')
        ->and($policy->failurePolicy(MetadataSource::Garuda))->toBe('fatal')
        ->and($policy->failurePolicy(MetadataSource::SemanticScholar))->toBe('degrade')
        ->and($policy->failurePolicy(MetadataSource::OpenAlex))->toBe('degrade')
        ->and($policy->isFatal(MetadataSource::Crossref))->toBeTrue()
        ->and($policy->isDegradable(MetadataSource::OpenAlex))->toBeTrue();

    config(['analysis.metadata.failure_policies.crossref' => 'degrade']);

    expect(app(SourceChainPolicy::class)->isDegradable(MetadataSource::Crossref))->toBeTrue();
});

it('fails loudly on an unknown failure policy or stop policy', function () {
    config(['analysis.metadata.failure_policies.crossref' => 'ignore']);

    expect(fn () => app(SourceChainPolicy::class))->toThrow(LogicException::class);

    config([
        'analysis.metadata.failure_policies.crossref' => 'fatal',
        'analysis.metadata.stop_policy' => 'anything',
    ]);

    expect(fn () => app(SourceChainPolicy::class))->toThrow(LogicException::class);
});

it('reports per-source and document-wide fallback budget exhaustion', function () {
    $policy = app(SourceChainPolicy::class);

    config(['services.garuda.max_requests_per_document' => 1]);

    $state = policyState(fallbackBudget: 10);
    $state->recordLookup(MetadataSource::Garuda, fallback: true);

    expect($policy->budgetExhausted(MetadataSource::Garuda, $state))->toBeTrue()
        ->and($policy->budgetExhausted(MetadataSource::OpenAlex, $state))->toBeFalse();

    $global = policyState(fallbackBudget: 2);
    $global->recordLookup(MetadataSource::Garuda, fallback: true);
    $global->recordLookup(MetadataSource::OpenAlex, fallback: true);

    expect($policy->budgetExhausted(MetadataSource::SemanticScholar, $global))->toBeTrue();
});

it('never applies the fallback budget to a primary source', function () {
    $policy = app(SourceChainPolicy::class);

    $state = policyState(fallbackBudget: 1);
    $state->recordLookup(MetadataSource::Garuda, fallback: true);

    expect($policy->budgetExhausted(MetadataSource::Crossref, $state, fallback: false))->toBeFalse();
});

it('reports the wall-clock fallback cap', function () {
    $policy = app(SourceChainPolicy::class);

    $state = policyState(fallbackBudget: 300, fallbackSeconds: 300, startedAgo: 400);

    expect($policy->budgetExhausted(MetadataSource::OpenAlex, $state))->toBeTrue();
});

it('does not exhaust the time budget on a freshly created run state', function () {
    $policy = app(SourceChainPolicy::class);
    $state = new SourceChainState(documentId: 'doc-1', fallbackBudget: 10, fallbackSeconds: 300);

    expect($policy->budgetExhausted(MetadataSource::OpenAlex, $state))->toBeFalse();
});
