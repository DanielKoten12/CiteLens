<?php

use App\Enums\MetadataSource;
use App\Services\Metadata\DoiNormalizer;
use App\Services\OpenAlex\OpenAlexResultMapper;
use Tests\Support\Fixtures;

beforeEach(function (): void {
    $this->mapper = new OpenAlexResultMapper(new DoiNormalizer);
});

it('maps a works response with normalized doi and provenance', function () {
    $works = $this->mapper->mapSearch(Fixtures::json('openalex/search-results'));

    expect($works)->toHaveCount(2);

    $first = $works[0];

    expect($first->doi)->toBe('10.1234/openalex-jesasi-2026')
        ->and($first->title)->toStartWith('Beyond Borders, Beyond Classrooms')
        ->and($first->authors)->toBe(['Dwi Enggrina', 'A. W. Liwang', 'W. Rahman'])
        ->and($first->containerTitle)->toBe('JESASI (Jurnal Edukasi, Sains, dan Inovasi)')
        ->and($first->publicationYear)->toBe(2026)
        ->and($first->url)->toBe('https://doi.org/10.1234/openalex-jesasi-2026')
        ->and($first->type)->toBe('article')
        ->and($first->source)->toBe(MetadataSource::OpenAlex);

    expect($works[1]->doi)->toBeNull()
        ->and($works[1]->url)->toBe('https://metadata.example.org/unrelated');
});

it('falls back to raw author names, landing page and record id', function () {
    $work = $this->mapper->mapWork([
        'id' => 'https://openalex.org/W1',
        'title' => 'A study',
        'authorships' => [
            ['author' => ['display_name' => 'First Author']],
            ['author' => null, 'raw_author_name' => 'Second Author'],
        ],
        'primary_location' => ['landing_page_url' => 'https://example.org/landing'],
    ]);

    expect($work?->authors)->toBe(['First Author', 'Second Author'])
        ->and($work?->url)->toBe('https://example.org/landing');

    $recordOnly = $this->mapper->mapWork([
        'id' => 'https://openalex.org/W2',
        'display_name' => 'Record only',
    ]);

    expect($recordOnly?->url)->toBe('https://openalex.org/W2')
        ->and($recordOnly?->title)->toBe('Record only');
});

it('guards the publication year and skips empty works', function () {
    expect($this->mapper->mapWork(['title' => 'A', 'publication_year' => 999])?->publicationYear)->toBeNull()
        ->and($this->mapper->mapWork(['title' => 'A', 'publication_year' => 2101])?->publicationYear)->toBeNull()
        ->and($this->mapper->mapWork(['title' => 'A', 'publication_year' => '2015'])?->publicationYear)->toBe(2015)
        ->and($this->mapper->mapWork(['id' => 'https://openalex.org/W3']))->toBeNull()
        ->and($this->mapper->mapSearch(['results' => 'not-a-list']))->toBe([]);
});
