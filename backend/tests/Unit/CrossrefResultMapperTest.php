<?php

use App\Services\Crossref\CrossrefResultMapper;
use App\Services\Crossref\DoiNormalizer;
use Tests\Support\Fixtures;

function mapper(): CrossrefResultMapper
{
    return new CrossrefResultMapper(new DoiNormalizer);
}

it('maps a full crossref work payload', function () {
    $work = mapper()->mapSingle(Fixtures::json('crossref/doi-found'));

    expect($work)->not->toBeNull()
        ->doi->toBe('10.1038/nature14539')
        ->title->toBe('Deep learning')
        ->containerTitle->toBe('Nature')
        ->publicationYear->toBe(2015)
        ->url->toBe('https://doi.org/10.1038/nature14539')
        ->authors->toBe(['Yann LeCun', 'Yoshua Bengio', 'Geoffrey Hinton']);
});

it('tolerates a work with missing title, issued and container', function () {
    $work = mapper()->mapSingle(Fixtures::json('crossref/malformed'));

    expect($work)->not->toBeNull()
        ->doi->toBe('10.9999/malformed.1')
        ->title->toBeNull()
        ->publicationYear->toBeNull()
        ->containerTitle->toBeNull()
        ->authors->toBe([]);
});

it('maps a work list and skips rows with neither doi nor title', function () {
    $works = mapper()->mapList([
        'message' => [
            'items' => [
                ['DOI' => '10.1234/one', 'title' => ['First']],
                ['DOI' => null, 'title' => null, 'author' => []],
                ['title' => ['Only a title'], 'issued' => ['date-parts' => [[2021]]]],
            ],
        ],
    ]);

    expect($works)->toHaveCount(2)
        ->and($works[0]->doi)->toBe('10.1234/one')
        ->and($works[1]->doi)->toBeNull()
        ->and($works[1]->publicationYear)->toBe(2021);
});

it('returns an empty list for an empty or malformed message', function () {
    expect(mapper()->mapList(['message' => ['items' => []]]))->toBe([])
        ->and(mapper()->mapList([]))->toBe([])
        ->and(mapper()->mapSingle(['message' => 'nope']))->toBeNull();
});

it('accepts a string title and a timestamp issued date', function () {
    $work = mapper()->mapWork([
        'DOI' => '10.1234/timestamp',
        'title' => '  Single title  ',
        'issued' => ['timestamp' => 1609459200000],
    ]);

    expect($work)->not->toBeNull()
        ->title->toBe('Single title')
        ->publicationYear->toBe(2021);
});

it('lowercases and strips url prefixes from the doi', function () {
    $work = mapper()->mapWork([
        'DOI' => 'https://doi.org/10.1038/NATURE14539',
        'title' => ['Deep learning'],
    ]);

    expect($work)->not->toBeNull()->doi->toBe('10.1038/nature14539');
});
