<?php

use App\Services\Citations\CitationMatchConfig;
use App\Services\Scoring\ScoringConfig;

it('loads the provisional citation threshold', function () {
    $config = app(CitationMatchConfig::class);

    expect($config->surnameThreshold())->toBe(0.85)
        ->and($config->yearTolerance())->toBe(app(ScoringConfig::class)->yearTolerance());
});

it('clamps the configured threshold to 0..1', function () {
    config(['scoring.citation_matching.surname_threshold' => 2.5]);

    expect(app(CitationMatchConfig::class)->surnameThreshold())->toBe(1.0);

    config(['scoring.citation_matching.surname_threshold' => -1.0]);

    expect(app(CitationMatchConfig::class)->surnameThreshold())->toBe(0.0);
});

it('shares the year tolerance with reference scoring', function () {
    config(['scoring.year_tolerance' => 3]);

    expect(app(CitationMatchConfig::class)->yearTolerance())->toBe(3);
});
