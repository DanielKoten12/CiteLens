<?php

use App\Enums\MetadataSource;
use App\Services\Garuda\GarudaResultMapper;
use App\Services\Scoring\StringSimilarity;

function garudaCard(array $overrides = []): array
{
    return array_merge([
        'id' => '3837540',
        'title' => 'Literasi Digital Sebagai Upaya Menangkal Hoax',
        'authors' => ['Aveny Kurnia', 'Yozan Trio'],
        'journal' => 'Jurnal Tonggak Pendidikan Dasar Vol. 2 No. 1 (2023): JTPD',
        'publisher' => 'Universitas Jambi',
        'year' => 2023,
    ], $overrides);
}

function garudaMapper(): GarudaResultMapper
{
    return new GarudaResultMapper(new StringSimilarity);
}

it('maps a card to a garuda work with a detail url and no doi', function () {
    $work = garudaMapper()->map(garudaCard(), 'https://garuda.kemdiktisaintek.go.id/');

    expect($work)->not->toBeNull()
        ->and($work->source)->toBe(MetadataSource::Garuda)
        ->and($work->doi)->toBeNull()
        ->and($work->title)->toBe('Literasi Digital Sebagai Upaya Menangkal Hoax')
        ->and($work->authors)->toBe(['Aveny Kurnia', 'Yozan Trio'])
        ->and($work->containerTitle)->toBe('Jurnal Tonggak Pendidikan Dasar')
        ->and($work->publicationYear)->toBe(2023)
        ->and($work->url)->toBe('https://garuda.kemdiktisaintek.go.id/documents/detail/3837540');
});

it('collapses a title that garuda duplicated after a colon', function () {
    $title = 'Literasi Digital: Kajian Kritis: Literasi Digital: Kajian Kritis';

    $work = garudaMapper()->map(garudaCard(['title' => $title]), 'https://garuda.test');

    expect($work?->title)->toBe('Literasi Digital: Kajian Kritis');
});

it('keeps a distinct subtitle', function () {
    $work = garudaMapper()->map(
        garudaCard(['title' => 'Literasi Digital: Tinjauan Kritis di Indonesia']),
        'https://garuda.test',
    );

    expect($work?->title)->toBe('Literasi Digital: Tinjauan Kritis di Indonesia');
});

it('skips a card without a usable title', function () {
    expect(garudaMapper()->map(garudaCard(['title' => '   ']), 'https://garuda.test'))->toBeNull();
});
