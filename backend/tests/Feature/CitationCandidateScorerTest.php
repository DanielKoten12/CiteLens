<?php

use App\Enums\CitationResolutionMethod;
use App\Services\Citations\CitationCandidateScorer;
use App\Services\Citations\CitationReference;
use App\Services\Citations\ParsedAuthorYear;
use App\Services\Scoring\AuthorMatcher;
use App\Services\Scoring\StringSimilarity;

beforeEach(function () {
    $this->matcher = new AuthorMatcher(new StringSimilarity);
    $this->scorer = app(CitationCandidateScorer::class);
});

function citationScoredPair(string $authors, ?int $year): ParsedAuthorYear
{
    return new ParsedAuthorYear(test()->matcher->names($authors), $year);
}

function citationScoredReference(string $id, string $authors, ?int $year, int $index): CitationReference
{
    return new CitationReference($id, test()->matcher->names($authors), $year, $index);
}

it('scores a surname and exact year to one', function () {
    $candidates = $this->scorer->score(
        citationScoredPair('Koten, D.', 2023),
        [citationScoredReference('ref-1', 'Koten, D.', 2023, 1)],
    );

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->confidence)->toBe(1.0)
        ->and($candidates[0]->method)->toBe(CitationResolutionMethod::Apa)
        ->and($candidates[0]->rank)->toBe(1);
});

it('renormalizes weights when the year is missing', function () {
    $candidates = $this->scorer->score(
        citationScoredPair('Koten, D.', null),
        [citationScoredReference('ref-1', 'Koten, D.', 2023, 1)],
    );

    expect($candidates[0]->confidence)->toBe(1.0);
});

it('penalizes a mismatching first initial', function () {
    $matching = $this->scorer->score(
        citationScoredPair('Koten, A.', 2023),
        [citationScoredReference('ref-1', 'Koten, A.', 2023, 1)],
    );

    $mismatching = $this->scorer->score(
        citationScoredPair('Koten, A.', 2023),
        [citationScoredReference('ref-1', 'Koten, D.', 2023, 1)],
    );

    expect($matching[0]->confidence)->toBe(1.0)
        ->and($mismatching[0]->confidence)->toBeLessThan($matching[0]->confidence)
        ->and($mismatching[0]->confidence)->toBeGreaterThan(0.85);
});

it('does not penalize initials when only one side exposes them', function () {
    $candidates = $this->scorer->score(
        citationScoredPair('Koten', 2023),
        [citationScoredReference('ref-1', 'Koten, D.', 2023, 1)],
    );

    expect($candidates[0]->confidence)->toBe(1.0);
});

it('ranks higher confidence first and breaks ties by bibliography position', function () {
    $candidates = $this->scorer->score(
        citationScoredPair('Koten, D.', 2023),
        [
            citationScoredReference('ref-late', 'Koten, D.', 1999, 2),
            citationScoredReference('ref-early', 'Koten, D.', 2023, 1),
        ],
    );

    expect($candidates)->toHaveCount(2)
        ->and($candidates[0]->referenceId)->toBe('ref-early')
        ->and($candidates[1]->referenceId)->toBe('ref-late')
        ->and($candidates[0]->rank)->toBe(1)
        ->and($candidates[1]->rank)->toBe(2);
});

it('drops candidates below the proposal threshold', function () {
    $candidates = $this->scorer->score(
        citationScoredPair('Koten, D.', 2023),
        [citationScoredReference('ref-1', 'Hidayat, A.', 1990, 1)],
    );

    expect($candidates)->toBe([]);
});

it('returns no candidate when neither side has authors', function () {
    $candidates = $this->scorer->score(
        new ParsedAuthorYear([], 2023),
        [citationScoredReference('ref-1', 'Koten, D.', 2023, 1)],
    );

    expect($candidates)->toBe([]);
});

it('builds a deterministic match reason', function () {
    $candidates = $this->scorer->score(
        citationScoredPair('Koten, D.', 2023),
        [citationScoredReference('ref-1', 'Koten, D.', 2023, 1)],
    );

    expect($candidates[0]->matchReason)->toBe('Kemiripan nama 1.00; tahun cocok.');
});
