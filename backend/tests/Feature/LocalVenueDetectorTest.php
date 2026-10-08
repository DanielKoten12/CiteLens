<?php

use App\Services\Crossref\CrossrefWorkData;
use App\Services\Scoring\LocalVenueDetector;
use App\Services\Scoring\ScoreBreakdown;
use App\Services\Scoring\ScoredCandidate;
use App\Services\Scoring\ScoringReference;

function venueReference(?string $publicationName): ScoringReference
{
    return new ScoringReference(
        id: 'ref-1',
        title: 'Sistem deteksi plagiarisme',
        authors: 'Koten, D.',
        publicationName: $publicationName,
        publicationYear: 2023,
        doi: null,
        doiValid: false,
    );
}

function venueCandidate(?string $containerTitle): ScoredCandidate
{
    return new ScoredCandidate(
        work: new CrossrefWorkData(doi: '10.1/x', title: 'Sistem deteksi plagiarisme', containerTitle: $containerTitle),
        breakdown: new ScoreBreakdown(
            signals: ['title' => 0.9, 'authors' => null, 'journal' => null, 'year' => null],
            final: 0.9,
            doiMatch: false,
            semanticUsed: false,
            semanticDegraded: false,
        ),
        rank: 1,
        matchReason: '',
    );
}

it('never matches when the keyword list is empty', function () {
    config(['scoring.local_venue_keywords' => []]);

    expect(app(LocalVenueDetector::class)->looksLocal(venueReference('Jurnal Lokal'), venueCandidate(null)))->toBeFalse();
});

it('matches a configured keyword in the reference publication name', function () {
    config(['scoring.local_venue_keywords' => ['jurnal lokal']]);

    expect(app(LocalVenueDetector::class)->looksLocal(venueReference('Jurnal Lokal Informatika'), null))->toBeTrue();
});

it('matches a configured keyword in the candidate container title', function () {
    config(['scoring.local_venue_keywords' => ['prosiding nasional']]);

    expect(app(LocalVenueDetector::class)->looksLocal(venueReference(null), venueCandidate('Prosiding Nasional Teknologi')))->toBeTrue();
});

it('does not match unrelated venues', function () {
    config(['scoring.local_venue_keywords' => ['jurnal lokal']]);

    expect(app(LocalVenueDetector::class)->looksLocal(venueReference('Nature'), venueCandidate('Nature')))->toBeFalse();
});
