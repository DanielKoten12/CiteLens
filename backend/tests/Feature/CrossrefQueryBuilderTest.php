<?php

use App\Models\ResearchedDocumentReference;
use App\Services\Crossref\CrossrefQueryBuilder;
use App\Services\Crossref\ReferenceQuery;

beforeEach(function (): void {
    config([
        'services.crossref.rows' => 5,
        'services.crossref.mailto' => 'test@example.com',
    ]);
});

it('builds the documented search params', function () {
    $params = app(CrossrefQueryBuilder::class)->searchParams(
        new ReferenceQuery('Sistem deteksi plagiarisme Koten 2023', 'Koten', 2023),
    );

    expect($params)
        ->toMatchArray([
            'query.bibliographic' => 'Sistem deteksi plagiarisme Koten 2023',
            'query.author' => 'Koten',
            'rows' => 5,
            'mailto' => 'test@example.com',
        ])
        ->and($params['select'])->toContain('DOI', 'title', 'author', 'container-title');
});

it('omits the author and mailto when absent', function () {
    config(['services.crossref.mailto' => '']);

    $params = app(CrossrefQueryBuilder::class)->searchParams(new ReferenceQuery('Deep learning'));

    expect($params)->not->toHaveKey('query.author')
        ->and($params)->not->toHaveKey('mailto');
});

it('keeps the registrar slash as a path separator and encodes the suffix', function () {
    $builder = app(CrossrefQueryBuilder::class);

    expect($builder->doiPath('10.1038/nature14539'))->toBe('works/10.1038/nature14539')
        ->and($builder->doiPath('10.1000/a b?c'))->toBe('works/10.1000/a%20b%3Fc');
});

it('derives a reference query from a bibliography entry', function () {
    $reference = new ResearchedDocumentReference([
        'title' => 'Sistem deteksi plagiarisme',
        'authors' => 'Koten, D. B., & Tani, R.',
        'publication_year' => 2023,
    ]);

    $query = ReferenceQuery::fromReference($reference);

    expect($query->author)->toBe('Koten')
        ->and($query->year)->toBe(2023)
        ->and($query->bibliographic)->toBe('Sistem deteksi plagiarisme Koten 2023');
});

it('derives the last word as the surname for western name order', function () {
    $reference = new ResearchedDocumentReference([
        'title' => 'Deep learning',
        'authors' => 'Yann LeCun',
        'publication_year' => 2015,
    ]);

    expect(ReferenceQuery::fromReference($reference)->author)->toBe('LeCun');
});
