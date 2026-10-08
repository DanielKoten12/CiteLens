<?php

use App\Enums\ReferenceFindingStatus;
use App\Services\Crossref\CrossrefWorkData;
use App\Services\Crossref\DoiLookup;
use App\Services\Scoring\LocalVenueDetector;
use App\Services\Scoring\MatchReasonBuilder;
use App\Services\Scoring\ScoreBreakdown;
use App\Services\Scoring\ScoredCandidate;
use App\Services\Scoring\ScoringConfig;
use App\Services\Scoring\ScoringReference;
use App\Services\Scoring\VerdictDecider;

beforeEach(function () {
    config([
        'scoring.thresholds.valid' => 0.85,
        'scoring.thresholds.suspicious' => 0.50,
        'scoring.local_venue_keywords' => [],
    ]);

    $this->decider = new VerdictDecider(
        app(ScoringConfig::class),
        app(LocalVenueDetector::class),
        new MatchReasonBuilder,
    );
});

function verdictReference(?string $doi = null, ?string $publicationName = null): ScoringReference
{
    return new ScoringReference(
        id: 'ref-1',
        title: 'Deep learning',
        authors: 'LeCun, Y.',
        publicationName: $publicationName,
        publicationYear: 2015,
        doi: $doi,
        doiValid: $doi !== null,
    );
}

function rankedCandidate(float $confidence, int $rank, bool $doiMatch = false, array $conflicts = []): ScoredCandidate
{
    return new ScoredCandidate(
        work: new CrossrefWorkData(doi: $doiMatch ? '10.1/same' : '10.1/other', title: 'Deep learning'),
        breakdown: new ScoreBreakdown(
            signals: ['title' => $confidence, 'authors' => null, 'journal' => null, 'year' => null],
            final: $confidence,
            doiMatch: $doiMatch,
            semanticUsed: false,
            semanticDegraded: false,
            conflicts: $conflicts,
        ),
        rank: $rank,
        matchReason: '',
    );
}

it('returns pending for a transient failure', function () {
    $verdict = $this->decider->decide(verdictReference('10.1/same'), DoiLookup::Resolved, true, []);

    expect($verdict->status)->toBe(ReferenceFindingStatus::Pending)
        ->and($verdict->confidence)->toBeNull()
        ->and($verdict->reason)->toBe(MatchReasonBuilder::TRANSIENT);
});

it('returns invalid for a malformed doi', function () {
    $verdict = $this->decider->decide(verdictReference('not-a-doi'), DoiLookup::Malformed, false, []);

    expect($verdict->status)->toBe(ReferenceFindingStatus::Invalid)
        ->and($verdict->reason)->toBe(MatchReasonBuilder::MALFORMED_DOI);
});

it('returns invalid when the doi does not resolve', function () {
    $verdict = $this->decider->decide(
        verdictReference('10.1/missing'),
        DoiLookup::NotFound,
        false,
        [rankedCandidate(0.95, 1)],
    );

    expect($verdict->status)->toBe(ReferenceFindingStatus::Invalid)
        ->and($verdict->reason)->toBe(MatchReasonBuilder::DOI_NOT_FOUND)
        ->and($verdict->selectedRank)->toBe(1);
});

it('returns valid when the doi resolves and the metadata agrees', function () {
    $verdict = $this->decider->decide(
        verdictReference('10.1/same'),
        DoiLookup::Resolved,
        false,
        [rankedCandidate(0.90, 1), rankedCandidate(0.88, 2, doiMatch: true)],
    );

    expect($verdict->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($verdict->reason)->toBe(MatchReasonBuilder::DOI_MATCH)
        ->and($verdict->selectedRank)->toBe(2)
        ->and($verdict->confidence)->toBe(0.88);
});

it('returns invalid when the doi resolves but the metadata conflicts', function () {
    $verdict = $this->decider->decide(
        verdictReference('10.1/same'),
        DoiLookup::Resolved,
        false,
        [rankedCandidate(0.70, 1), rankedCandidate(0.30, 2, doiMatch: true, conflicts: ['title'])],
    );

    expect($verdict->status)->toBe(ReferenceFindingStatus::Invalid)
        ->and($verdict->reason)->toBe('DOI menunjuk ke publikasi yang berbeda. Perbedaan: judul.')
        ->and($verdict->confidence)->toBe(0.30)
        ->and($verdict->selectedRank)->toBe(1);
});

it('returns not_found when no candidate exists', function () {
    $verdict = $this->decider->decide(verdictReference(), DoiLookup::NotPresent, false, []);

    expect($verdict->status)->toBe(ReferenceFindingStatus::NotFound)
        ->and($verdict->reason)->toBe(MatchReasonBuilder::NOT_FOUND);
});

it('returns valid with a suggested doi when the best candidate clears the threshold', function () {
    $verdict = $this->decider->decide(verdictReference(), DoiLookup::NotPresent, false, [rankedCandidate(0.86, 1)]);

    expect($verdict->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($verdict->selectedRank)->toBe(1)
        ->and($verdict->reason)->toBe('Kandidat dengan DOI 10.1/other memiliki kemiripan tinggi.');
});

it('returns suspicious between the thresholds', function () {
    $verdict = $this->decider->decide(verdictReference(), DoiLookup::NotPresent, false, [rankedCandidate(0.60, 1)]);

    expect($verdict->status)->toBe(ReferenceFindingStatus::Suspicious)
        ->and($verdict->reason)->toBe(MatchReasonBuilder::SUSPICIOUS);
});

it('returns not_found below the suspicious threshold when the venue is not local', function () {
    $verdict = $this->decider->decide(verdictReference(), DoiLookup::NotPresent, false, [rankedCandidate(0.30, 1)]);

    expect($verdict->status)->toBe(ReferenceFindingStatus::NotFound)
        ->and($verdict->selectedRank)->toBeNull();
});

it('returns suspicious for a low score from a local venue', function () {
    // T-SCORE-06
    config(['scoring.local_venue_keywords' => ['Jurnal Lokal']]);

    $verdict = $this->decider->decide(
        verdictReference(publicationName: 'Jurnal Lokal Informatika'),
        DoiLookup::NotPresent,
        false,
        [rankedCandidate(0.30, 1)],
    );

    expect($verdict->status)->toBe(ReferenceFindingStatus::Suspicious)
        ->and($verdict->reason)->toBe(MatchReasonBuilder::LOCAL_VENUE);
});

it('returns suspicious for a local venue with no candidates at all', function () {
    config(['scoring.local_venue_keywords' => ['Jurnal Lokal']]);

    $verdict = $this->decider->decide(
        verdictReference(publicationName: 'Jurnal Lokal Informatika'),
        DoiLookup::NotPresent,
        false,
        [],
    );

    expect($verdict->status)->toBe(ReferenceFindingStatus::Suspicious);
});

it('treats the valid threshold as inclusive', function () {
    $at = $this->decider->decide(verdictReference(), DoiLookup::NotPresent, false, [rankedCandidate(0.85, 1)]);
    $below = $this->decider->decide(verdictReference(), DoiLookup::NotPresent, false, [rankedCandidate(0.8499, 1)]);

    expect($at->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($below->status)->toBe(ReferenceFindingStatus::Suspicious);
});
