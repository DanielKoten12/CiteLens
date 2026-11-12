<?php

use App\Enums\MetadataSource;
use App\Services\Metadata\Support\SourceCircuitBreaker;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    config([
        'analysis.metadata.circuit_breaker.enabled' => true,
        'analysis.metadata.circuit_breaker.failure_threshold' => 2,
        'analysis.metadata.circuit_breaker.cooldown_seconds' => 120,
    ]);

    $this->breaker = app(SourceCircuitBreaker::class);
});

it('opens only after the configured failure threshold', function () {
    $this->breaker->recordFailure(MetadataSource::SemanticScholar);

    expect($this->breaker->isOpen(MetadataSource::SemanticScholar))->toBeFalse();

    $this->breaker->recordFailure(MetadataSource::SemanticScholar);

    expect($this->breaker->isOpen(MetadataSource::SemanticScholar))->toBeTrue()
        ->and($this->breaker->isOpen(MetadataSource::OpenAlex))->toBeFalse();
});

it('resets the failure counter and the open state on success', function () {
    $this->breaker->recordFailure(MetadataSource::OpenAlex);
    $this->breaker->recordFailure(MetadataSource::OpenAlex);

    expect($this->breaker->isOpen(MetadataSource::OpenAlex))->toBeTrue();

    $this->breaker->recordSuccess(MetadataSource::OpenAlex);

    expect($this->breaker->isOpen(MetadataSource::OpenAlex))->toBeFalse();

    $this->breaker->recordFailure(MetadataSource::OpenAlex);

    expect($this->breaker->isOpen(MetadataSource::OpenAlex))->toBeFalse();
});

it('is a no-op when disabled', function () {
    config(['analysis.metadata.circuit_breaker.enabled' => false]);

    $this->breaker->recordFailure(MetadataSource::SemanticScholar);
    $this->breaker->recordFailure(MetadataSource::SemanticScholar);

    expect($this->breaker->isOpen(MetadataSource::SemanticScholar))->toBeFalse();
});
