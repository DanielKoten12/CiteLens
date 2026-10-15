<?php

use App\Services\Citations\CitationMatchConfig;

it('loads the provisional citation matching defaults', function () {
    $config = app(CitationMatchConfig::class);

    expect($config->weights())->toBe(['surnames' => 0.70, 'year' => 0.30])
        ->and($config->commitThreshold())->toBe(0.90)
        ->and($config->proposalThreshold())->toBe(0.50)
        ->and($config->winnerMargin())->toBe(0.08)
        ->and($config->yearWindow())->toBe(5)
        ->and($config->initialPenalty())->toBe(0.15)
        ->and($config->maxCandidates())->toBe(3)
        ->and($config->trustExtractionHint())->toBeTrue();
});

it('clamps thresholds to 0..1', function () {
    config([
        'scoring.citation_matching.commit_threshold' => 2.5,
        'scoring.citation_matching.proposal_threshold' => -1.0,
        'scoring.citation_matching.initial_penalty' => 5.0,
    ]);

    $config = app(CitationMatchConfig::class);

    expect($config->commitThreshold())->toBe(1.0)
        ->and($config->proposalThreshold())->toBe(0.0)
        ->and($config->initialPenalty())->toBe(1.0);
});

it('throws when the citation matching weights do not sum to one', function () {
    config([
        'scoring.citation_matching.weights.surnames' => 0.9,
        'scoring.citation_matching.weights.year' => 0.9,
    ]);

    expect(fn () => app(CitationMatchConfig::class)->weights())
        ->toThrow(InvalidArgumentException::class);
});

it('keeps the year window at least one', function () {
    config(['scoring.citation_matching.year_window' => 0]);

    expect(app(CitationMatchConfig::class)->yearWindow())->toBe(1);
});
