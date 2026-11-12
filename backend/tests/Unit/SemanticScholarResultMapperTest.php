<?php

use App\Enums\MetadataSource;
use App\Services\Metadata\DoiNormalizer;
use App\Services\SemanticScholar\SemanticScholarResultMapper;
use Tests\Support\Fixtures;

beforeEach(function (): void {
    $this->mapper = new SemanticScholarResultMapper(new DoiNormalizer);
});

it('maps a search response with provenance and normalized fields', function () {
    $works = $this->mapper->mapSearch(Fixtures::json('semantic_scholar/search-results'));

    expect($works)->toHaveCount(2);

    $first = $works[0];

    expect($first->doi)->toBe('10.1234/s2-jesasi-2026')
        ->and($first->title)->toBe('Beyond Borders, Beyond Classrooms: Pendampingan Literasi Finansial Digital melalui Aktivitas Edukatif bagi Anak Diaspora Indonesia di Sanggar Bimbingan Kampung Bharu, Malaysia')
        ->and($first->authors)->toBe(['Dwi Enggrina', 'A. W. Liwang', 'W. Rahman'])
        ->and($first->containerTitle)->toBe('JESASI (Jurnal Edukasi, Sains, dan Inovasi)')
        ->and($first->publicationYear)->toBe(2026)
        ->and($first->url)->toBe('https://www.semanticscholar.org/paper/s2-multi-source-001')
        ->and($first->type)->toBe('journalarticle')
        ->and($first->source)->toBe(MetadataSource::SemanticScholar);
});

it('uses venue fallbacks and derives a paper url', function () {
    $work = $this->mapper->mapPaper([
        'paperId' => 'abc123',
        'title' => 'A study',
        'venue' => 'Fallback Venue',
    ]);

    expect($work?->containerTitle)->toBe('Fallback Venue')
        ->and($work?->url)->toBe('https://www.semanticscholar.org/paper/abc123');

    $viaPublicationVenue = $this->mapper->mapPaper([
        'paperId' => 'abc124',
        'title' => 'A study',
        'publicationVenue' => ['name' => 'Publication Venue'],
        'venue' => 'Fallback Venue',
    ]);

    expect($viaPublicationVenue?->containerTitle)->toBe('Publication Venue');
});

it('guards the publication year and normalizes a doi url', function () {
    expect($this->mapper->mapPaper(['title' => 'A', 'year' => 999])?->publicationYear)->toBeNull()
        ->and($this->mapper->mapPaper(['title' => 'A', 'year' => 2101])?->publicationYear)->toBeNull()
        ->and($this->mapper->mapPaper(['title' => 'A', 'year' => '2015'])?->publicationYear)->toBe(2015)
        ->and($this->mapper->mapPaper([
            'title' => 'A',
            'externalIds' => ['DOI' => 'https://doi.org/10.1038/NATURE14539'],
        ])?->doi)->toBe('10.1038/nature14539');
});

it('skips records with neither a doi nor a title and tolerates bad shapes', function () {
    expect($this->mapper->mapPaper(['paperId' => 'no-metadata']))->toBeNull()
        ->and($this->mapper->mapPaper([
            'paperId' => 'doi-only',
            'externalIds' => ['DOI' => '10.1234/x'],
        ])?->doi)->toBe('10.1234/x')
        ->and($this->mapper->mapSearch(['data' => 'not-a-list']))->toBe([])
        ->and($this->mapper->mapPaper([
            'title' => 'Odd shapes',
            'authors' => 'not-a-list',
            'publicationTypes' => 'not-a-list',
            'journal' => 'not-a-list',
        ])?->authors)->toBe([]);
});
