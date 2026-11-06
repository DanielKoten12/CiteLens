<?php

use App\Enums\MetadataSource;
use App\Services\Metadata\WorkMetadata;
use App\Services\Metadata\WorkMetadataMerger;
use App\Services\Scoring\StringSimilarity;

beforeEach(function (): void {
    $this->merger = new WorkMetadataMerger(new StringSimilarity);
});

function work(array $overrides = []): WorkMetadata
{
    return new WorkMetadata(
        doi: $overrides['doi'] ?? null,
        title: $overrides['title'] ?? 'A study',
        authors: $overrides['authors'] ?? [],
        containerTitle: $overrides['containerTitle'] ?? null,
        publicationYear: $overrides['publicationYear'] ?? null,
        url: $overrides['url'] ?? null,
        type: $overrides['type'] ?? null,
        source: $overrides['source'] ?? MetadataSource::Crossref,
    );
}

it('de-duplicates by normalized doi and keeps the first occurrence', function () {
    $merged = $this->merger->merge(
        [work(['doi' => '10.1234/x', 'source' => MetadataSource::Crossref])],
        [
            work(['doi' => '10.1234/x', 'source' => MetadataSource::OpenAlex, 'title' => 'Renamed']),
            work(['doi' => '10.1234/y']),
        ],
    );

    expect($merged)->toHaveCount(2)
        ->and($merged[0]->doi)->toBe('10.1234/x')
        ->and($merged[0]->source)->toBe(MetadataSource::Crossref)
        ->and($merged[0]->title)->toBe('A study')
        ->and($merged[1]->doi)->toBe('10.1234/y');
});

it('de-duplicates doi-less works by normalized title', function () {
    $merged = $this->merger->merge(
        [work(['title' => 'State-of-the-Art: Deep Learning!'])],
        [work(['title' => 'state of the art deep learning'])],
    );

    expect($merged)->toHaveCount(1);
});

it('promotes a later doi-bearing record over an earlier doi-less one', function () {
    $garuda = work([
        'title' => 'Beyond Borders: Digital Literacy',
        'authors' => ['D. Enggrina'],
        'containerTitle' => 'JESASI',
        'publicationYear' => 2026,
        'url' => 'https://garuda.example/1',
        'source' => MetadataSource::Garuda,
    ]);

    $openAlex = work([
        'doi' => '10.1234/promoted',
        'title' => 'Beyond Borders - Digital Literacy',
        'authors' => [],
        'containerTitle' => null,
        'publicationYear' => 2026,
        'url' => 'https://doi.org/10.1234/promoted',
        'source' => MetadataSource::OpenAlex,
    ]);

    $merged = $this->merger->merge([$garuda], [$openAlex]);

    expect($merged)->toHaveCount(1)
        ->and($merged[0]->doi)->toBe('10.1234/promoted')
        ->and($merged[0]->source)->toBe(MetadataSource::OpenAlex)
        ->and($merged[0]->title)->toBe('Beyond Borders - Digital Literacy')
        ->and($merged[0]->authors)->toBe(['D. Enggrina'])
        ->and($merged[0]->containerTitle)->toBe('JESASI')
        ->and($merged[0]->url)->toBe('https://doi.org/10.1234/promoted');
});

it('never promotes a doi-bearing record with a different title', function () {
    $merged = $this->merger->merge(
        [work(['title' => 'Correct DOI-less local article', 'source' => MetadataSource::Garuda])],
        [work(['doi' => '10.1234/wrong', 'title' => 'A different publication', 'source' => MetadataSource::OpenAlex])],
    );

    expect($merged)->toHaveCount(2);
});

it('keeps a stable source order and never promotes twice', function () {
    $merged = $this->merger->merge(
        [
            work(['title' => 'First', 'source' => MetadataSource::Crossref]),
            work(['title' => 'Target', 'source' => MetadataSource::Garuda]),
        ],
        [work(['doi' => '10.1234/target', 'title' => 'Target', 'source' => MetadataSource::SemanticScholar])],
    );

    expect(array_map(fn (WorkMetadata $work): string => (string) $work->title, $merged))
        ->toBe(['First', 'Target'])
        ->and($merged[1]->doi)->toBe('10.1234/target')
        ->and($merged[1]->source)->toBe(MetadataSource::SemanticScholar);
});

it('drops a doi-less duplicate that arrives after a promotion', function () {
    $merged = $this->merger->merge(
        [work(['title' => 'Local article', 'source' => MetadataSource::Garuda])],
        [
            work(['doi' => '10.1234/promoted', 'title' => 'Local article', 'source' => MetadataSource::OpenAlex]),
            work(['title' => 'Local article', 'source' => MetadataSource::SemanticScholar]),
        ],
    );

    expect($merged)->toHaveCount(1)
        ->and($merged[0]->doi)->toBe('10.1234/promoted')
        ->and($merged[0]->source)->toBe(MetadataSource::OpenAlex);
});

it('keeps two doi-bearing records with the same title but different dois', function () {
    $merged = $this->merger->merge(
        [work(['doi' => '10.1234/preprint', 'title' => 'Shared title'])],
        [work(['doi' => '10.1234/published', 'title' => 'Shared title'])],
    );

    expect($merged)->toHaveCount(2);
});
