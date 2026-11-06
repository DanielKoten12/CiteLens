<?php

use App\Services\Metadata\ReferenceQuery;
use App\Services\OpenAlex\OpenAlexQueryBuilder;
use App\Services\Scoring\StringSimilarity;

beforeEach(function (): void {
    config(['services.openalex.mailto' => null]);

    $this->builder = new OpenAlexQueryBuilder(new StringSimilarity);
});

it('builds a title search filter instead of a full-text search', function () {
    $params = $this->builder->searchParams(new ReferenceQuery(
        bibliographic: 'Deep learning LeCun 2015',
        author: 'LeCun',
        year: 2015,
        title: 'Deep Learning',
    ));

    expect($params['filter'])->toBe('title.search:deep learning')
        ->and($params)->not->toHaveKey('search')
        ->and($params['select'])->toBe('id,doi,title,publication_year,authorships,primary_location,type')
        ->and($params)->not->toHaveKey('year');
});

it('bounds per-page and omits an empty mailto', function () {
    config(['services.openalex.per_page' => 500]);
    expect($this->builder->searchParams(new ReferenceQuery(bibliographic: 'x', title: 'Deep learning'))['per-page'])->toBe(100);

    config(['services.openalex.per_page' => 0]);
    expect($this->builder->searchParams(new ReferenceQuery(bibliographic: 'x', title: 'Deep learning'))['per-page'])->toBe(1)
        ->and($this->builder->searchParams(new ReferenceQuery(bibliographic: 'x', title: 'Deep learning')))->not->toHaveKey('mailto');
});

it('adds the polite pool mailto when configured', function () {
    config(['services.openalex.mailto' => 'copy@example.org']);

    expect($this->builder->searchParams(new ReferenceQuery(bibliographic: 'x', title: 'Deep learning'))['mailto'])
        ->toBe('copy@example.org')
        ->and($this->builder->doiParams()['mailto'])->toBe('copy@example.org');
});

it('keeps the registrar slash and encodes unsafe doi segments', function () {
    expect($this->builder->doiPath('10.1038/nature14539'))
        ->toBe('works/doi:10.1038/nature14539')
        ->and($this->builder->doiPath('10.1234/a b?c'))
        ->toBe('works/doi:10.1234/a%20b%3Fc');
});
