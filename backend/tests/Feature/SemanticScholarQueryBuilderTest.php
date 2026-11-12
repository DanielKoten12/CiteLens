<?php

use App\Services\Metadata\ReferenceQuery;
use App\Services\Scoring\StringSimilarity;
use App\Services\SemanticScholar\SemanticScholarQueryBuilder;

beforeEach(function (): void {
    $this->builder = new SemanticScholarQueryBuilder(new StringSimilarity);
});

it('builds a title-only query and normalizes hyphens to spaces', function () {
    $params = $this->builder->searchParams(new ReferenceQuery(
        bibliographic: 'Deep learning LeCun 2015',
        author: 'LeCun',
        year: 2015,
        title: 'State-of-the-Art: Deep-Learning!',
    ));

    expect($params['query'])->toBe('state of the art deep learning')
        ->and($params['fields'])->toContain('externalIds')
        ->and($params['fields'])->toContain('publicationVenue')
        ->and($params)->not->toHaveKey('year');
});

it('falls back to the bibliographic query and caps its length', function () {
    config(['services.semantic_scholar.query_max_length' => 24]);

    $params = $this->builder->searchParams(new ReferenceQuery(
        bibliographic: 'A very long bibliographic query that must be truncated',
    ));

    expect(mb_strlen($params['query']))->toBeLessThanOrEqual(24)
        ->and($params['query'])->toBe('a very long bibliographi');
});

it('bounds the result limit to the api range', function () {
    config(['services.semantic_scholar.limit' => 500]);
    expect($this->builder->searchParams(new ReferenceQuery(bibliographic: 'x', title: 'Deep learning'))['limit'])->toBe(100);

    config(['services.semantic_scholar.limit' => 0]);
    expect($this->builder->searchParams(new ReferenceQuery(bibliographic: 'x', title: 'Deep learning'))['limit'])->toBe(1);
});

it('keeps the registrar slash and encodes unsafe doi segments', function () {
    expect($this->builder->doiPath('10.1038/nature14539'))
        ->toBe('graph/v1/paper/DOI:10.1038/nature14539')
        ->and($this->builder->doiPath('10.1234/a b?c'))
        ->toBe('graph/v1/paper/DOI:10.1234/a%20b%3Fc');
});
