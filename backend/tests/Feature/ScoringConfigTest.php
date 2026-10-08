<?php

use App\Services\Scoring\ScoringConfig;

it('loads the provisional defaults', function () {
    $config = app(ScoringConfig::class);

    expect($config->validThreshold())->toBe(0.85)
        ->and($config->suspiciousThreshold())->toBe(0.50)
        ->and($config->yearTolerance())->toBe(1)
        ->and($config->semanticEnabled())->toBeTrue()
        ->and($config->semanticTitleBlend())->toBe(0.6)
        ->and($config->localVenueKeywords())->toBe([])
        ->and(array_sum($config->weights()))->toEqualWithDelta(1.0, 0.001);
});

it('throws when the configured weights do not sum to one', function () {
    config([
        'scoring.weights.title' => 0.9,
        'scoring.weights.authors' => 0.25,
        'scoring.weights.journal' => 0.15,
        'scoring.weights.year' => 0.15,
    ]);

    expect(fn () => app(ScoringConfig::class)->weights())
        ->toThrow(InvalidArgumentException::class);
});

it('clamps thresholds and the semantic blend to 0..1', function () {
    config([
        'scoring.thresholds.valid' => 1.5,
        'scoring.semantic.title_blend' => -2.0,
    ]);

    $config = app(ScoringConfig::class);

    expect($config->validThreshold())->toBe(1.0)
        ->and($config->semanticTitleBlend())->toBe(0.0);
});
