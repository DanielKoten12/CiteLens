<?php

use App\Services\Citations\CitationMarkerParser;
use App\Services\Citations\CitationMatchConfig;
use App\Services\Citations\CitationReference;
use App\Services\Citations\CitationResolver;
use App\Services\Scoring\AuthorMatcher;
use App\Services\Scoring\ScoringConfig;
use App\Services\Scoring\StringSimilarity;

beforeEach(function () {
    $strings = new StringSimilarity;
    $authors = new AuthorMatcher($strings);

    $this->parser = new CitationMarkerParser($authors);
    $this->resolver = new CitationResolver(
        new CitationMatchConfig(app(ScoringConfig::class)),
        $strings,
        $authors,
    );
});

/**
 * @param  array<int, array{0: string, 1: ?string, 2: ?int}>  $references  id, authors, year
 * @return list<CitationReference>
 */
function citationReferences(array $references): array
{
    $models = [];

    foreach ($references as $index => [$id, $authors, $year]) {
        $models[] = new CitationReference($id, $authors, $year, $index + 1);
    }

    return $models;
}

it('pairs an apa citation by surname and year', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([
            ['ref-1', 'LeCun, Y.', 2015],
            ['ref-2', 'Koten, D. B.', 2023],
        ]),
    );

    expect($resolution->referenceId)->toBe('ref-2')
        ->and($resolution->confidence)->toBe(1.0);
});

it('pairs a surname typo above the threshold', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koton, 2023)'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
    );

    expect($resolution->referenceId)->toBe('ref-1')
        ->and($resolution->confidence)->toBeGreaterThan(0.85);
});

it('ignores a reference whose year is outside the tolerance', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([['ref-1', 'Koten, D.', 1999]]),
    );

    expect($resolution->isPaired())->toBeFalse();
});

it('accepts a year within tolerance', function () {
    config(['scoring.year_tolerance' => 1]);

    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([['ref-1', 'Koten, D.', 2024]]),
    );

    expect($resolution->referenceId)->toBe('ref-1');
});

it('does not gate on a missing reference year', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([['ref-1', 'Koten, D.', null]]),
    );

    expect($resolution->referenceId)->toBe('ref-1');
});

it('leaves an answer below the surname threshold unpaired', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([['ref-1', 'Hidayat, A.', 2023]]),
    );

    expect($resolution->isPaired())->toBeFalse();
});

it('breaks a tie with the earliest bibliography position', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([
            ['ref-earliest', 'Koten, D.', 2023],
            ['ref-later', 'Koten, D.', 2023],
        ]),
    );

    expect($resolution->referenceId)->toBe('ref-earliest');
});

it('orders references by their bibliography index regardless of input order', function () {
    $references = [
        new CitationReference('ref-b', 'Koten, D.', 2023, 2),
        new CitationReference('ref-a', 'Koten, D.', 2023, 1),
    ];

    $resolution = $this->resolver->resolve($this->parser->parse('(Koten, 2023)'), $references);

    expect($resolution->referenceId)->toBe('ref-a');
});

it('resolves an ieee ordinal to the bibliography position', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('[2]'),
        citationReferences([
            ['ref-1', 'LeCun, Y.', 2015],
            ['ref-2', 'Koten, D.', 2023],
        ]),
    );

    expect($resolution->referenceId)->toBe('ref-2')
        ->and($resolution->confidence)->toBe(1.0);
});

it('leaves an out-of-range ieee ordinal unpaired', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('[99]'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
    );

    expect($resolution->isPaired())->toBeFalse();
});

it('uses the lowest ordinal of an ieee range', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('[3-5]'),
        citationReferences([
            ['ref-1', 'A', 2000],
            ['ref-2', 'B', 2001],
            ['ref-3', 'C', 2002],
            ['ref-4', 'D', 2003],
        ]),
    );

    expect($resolution->referenceId)->toBe('ref-3');
});

it('leaves an unparseable marker unpaired', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('tanpa tahun'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
    );

    expect($resolution->isPaired())->toBeFalse();
});

it('penalizes a cited author the reference does not carry', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten & Tani, 2023)'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
    );

    expect($resolution->isPaired())->toBeFalse();
});

it('does not penalize an et al. citation with fewer surnames than the reference', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(LeCun et al., 2015)'),
        citationReferences([['ref-1', 'LeCun, Y., Bengio, Y., & Hinton, G.', 2015]]),
    );

    expect($resolution->referenceId)->toBe('ref-1');
});
