<?php

use App\Enums\CitationResolutionState;
use App\Services\Citations\CitationBatchResolver;
use App\Services\Citations\CitationMarkerParser;
use App\Services\Citations\CitationReference;
use App\Services\Citations\CitationResolutionInput;
use App\Services\Scoring\AuthorMatcher;
use App\Services\Scoring\StringSimilarity;

beforeEach(function () {
    $this->parser = app(CitationMarkerParser::class);
    $this->batch = app(CitationBatchResolver::class);
    $this->matcher = new AuthorMatcher(new StringSimilarity);
});

/**
 * @param  array<int, array{0: string, 1: string, 2: ?int}>  $references
 * @return list<CitationReference>
 */
function batchReferences(array $references): array
{
    $models = [];

    foreach ($references as $index => [$id, $authors, $year]) {
        $models[] = new CitationReference($id, test()->matcher->names($authors), $year, $index + 1);
    }

    return $models;
}

function batchInput(string $id, string $marker): CitationResolutionInput
{
    return new CitationResolutionInput($id, test()->parser->parse($marker));
}

it('propagates an unambiguous year to a sibling candidate', function () {
    $resolutions = $this->batch->resolve(
        batchReferences([['ref-1', 'Hartono, D.', 2023]]),
        [
            batchInput('c1', '(Hartono, 2023)'),
            batchInput('c2', '(Hartini)'),
        ],
    );

    expect($resolutions['c1']->state)->toBe(CitationResolutionState::Paired)
        ->and($resolutions['c1']->referenceId)->toBe('ref-1')
        ->and($resolutions['c2']->state)->toBe(CitationResolutionState::Paired)
        ->and($resolutions['c2']->referenceId)->toBe('ref-1');
});

it('does not consolidate when the paired siblings disagree on the year', function () {
    $resolutions = $this->batch->resolve(
        batchReferences([['ref-1', 'Hartono, D.', 2023]]),
        [
            batchInput('c1', '(Hartono, 2022)'),
            batchInput('c2', '(Hartono, 2023)'),
            batchInput('c3', '(Hartini)'),
        ],
    );

    expect($resolutions['c3']->state)->toBe(CitationResolutionState::Unmatched);
});

it('does not consolidate without a paired sibling', function () {
    $resolutions = $this->batch->resolve(
        batchReferences([['ref-1', 'Hartono, D.', 2023]]),
        [batchInput('c1', '(Hartini)')],
    );

    expect($resolutions['c1']->state)->toBe(CitationResolutionState::Unmatched);
});

it('never weakens a pair committed in the first pass', function () {
    $resolutions = $this->batch->resolve(
        batchReferences([['ref-1', 'Hartono, D.', 2023]]),
        [
            batchInput('c1', '(Hartono, 2023)'),
            batchInput('c2', '(Hartini)'),
        ],
    );

    expect($resolutions['c1']->referenceId)->toBe('ref-1')
        ->and($resolutions['c1']->confidence)->toBe(1.0);
});

it('does not propagate evidence from a different candidate', function () {
    $resolutions = $this->batch->resolve(
        batchReferences([
            ['ref-1', 'Hartono, D.', 2023],
            ['ref-2', 'Tani, B.', 2021],
        ]),
        [
            batchInput('c1', '(Tani, 2021)'),
            batchInput('c2', '(Hartini)'),
        ],
    );

    expect($resolutions['c2']->state)->toBe(CitationResolutionState::Unmatched);
});
