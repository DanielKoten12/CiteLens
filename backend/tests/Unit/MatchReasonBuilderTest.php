<?php

use App\Services\Crossref\CrossrefWorkData;
use App\Services\Scoring\MatchReasonBuilder;
use App\Services\Scoring\ScoreBreakdown;
use App\Services\Scoring\ScoredCandidate;

beforeEach(function () {
    $this->reasons = new MatchReasonBuilder;
});

it('builds a deterministic component-evidence candidate reason', function () {
    $breakdown = new ScoreBreakdown(
        signals: ['title' => 0.78, 'authors' => 0.91, 'journal' => 0.80, 'year' => 1.0],
        final: 0.85,
        doiMatch: false,
        semanticUsed: true,
        semanticDegraded: false,
        conflicts: [],
    );

    expect($this->reasons->forCandidate($breakdown))
        ->toBe('kemiripan judul 0.78; kemiripan penulis 0.91; kemiripan jurnal 0.80; tahun cocok.');
});

it('prefixes a doi match and records degraded semantics', function () {
    $breakdown = new ScoreBreakdown(
        signals: ['title' => 0.90, 'authors' => null, 'journal' => null, 'year' => null],
        final: 0.90,
        doiMatch: true,
        semanticUsed: false,
        semanticDegraded: true,
        conflicts: [],
    );

    expect($this->reasons->forCandidate($breakdown))
        ->toBe('DOI cocok; kemiripan judul 0.90; kemiripan semantik tidak tersedia.');
});

it('falls back when no signal is available', function () {
    $breakdown = new ScoreBreakdown(
        signals: ['title' => null, 'authors' => null, 'journal' => null, 'year' => null],
        final: 0.0,
        doiMatch: false,
        semanticUsed: false,
        semanticDegraded: false,
        conflicts: [],
    );

    expect($this->reasons->forCandidate($breakdown))->toBe('Tidak ada sinyal yang dapat dibandingkan.');
});

it('names conflicting fields in a doi conflict reason', function () {
    expect($this->reasons->forDoiConflict(['title', 'year']))
        ->toBe('DOI menunjuk ke publikasi yang berbeda. Perbedaan: judul, tahun.');
});

it('suggests the doi when the best candidate has one', function () {
    $candidate = new ScoredCandidate(
        work: new CrossrefWorkData(doi: '10.1234/example', title: 'Deep learning'),
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

    expect($this->reasons->forNoDoiValid($candidate))
        ->toBe('Kandidat dengan DOI 10.1234/example memiliki kemiripan tinggi.');
});
