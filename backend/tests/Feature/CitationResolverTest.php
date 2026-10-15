<?php

use App\Enums\CitationResolutionMethod;
use App\Enums\CitationResolutionState;
use App\Services\Citations\CitationMarkerParser;
use App\Services\Citations\CitationReference;
use App\Services\Citations\CitationResolver;
use App\Services\Scoring\AuthorMatcher;
use App\Services\Scoring\StringSimilarity;

beforeEach(function () {
    $this->parser = app(CitationMarkerParser::class);
    $this->resolver = app(CitationResolver::class);
});

/**
 * @param  array<int, array{0: string, 1: ?string, 2: ?int}>  $references  id, authors, year
 * @return list<CitationReference>
 */
function citationReferences(array $references): array
{
    $matcher = new AuthorMatcher(new StringSimilarity);

    $models = [];

    foreach ($references as $index => [$id, $authors, $year]) {
        $models[] = new CitationReference($id, $matcher->names($authors), $year, $index + 1);
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

    expect($resolution->state)->toBe(CitationResolutionState::Paired)
        ->and($resolution->referenceId)->toBe('ref-2')
        ->and($resolution->method)->toBe(CitationResolutionMethod::Apa)
        ->and($resolution->confidence)->toBe(1.0);
});

it('pairs a surname typo above the threshold', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koton, 2023)'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
    );

    expect($resolution->referenceId)->toBe('ref-1')
        ->and($resolution->confidence)->toBeGreaterThan(0.90);
});

it('penalizes a year outside the window instead of hard-rejecting', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 1999)'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
    );

    expect($resolution->state)->toBe(CitationResolutionState::Unresolved)
        ->and($resolution->candidates)->toHaveCount(1);
});

it('leaves a preprint/published year gap unresolved', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2020)'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
    );

    // 0.7 * 1.0 + 0.3 * (1 - 3/5) = 0.82 → below commit, above proposal.
    expect($resolution->state)->toBe(CitationResolutionState::Unresolved)
        ->and($resolution->candidates)->toHaveCount(1);
});

it('does not gate on a missing reference year', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([['ref-1', 'Koten, D.', null]]),
    );

    expect($resolution->referenceId)->toBe('ref-1');
});

it('leaves a low-similarity answer unmatched', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([['ref-1', 'Hidayat, A.', 2023]]),
    );

    expect($resolution->state)->toBe(CitationResolutionState::Unmatched)
        ->and($resolution->candidates)->toBe([]);
});

it('treats an exact tie as unresolved and orders candidates by bibliography position', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([
            ['ref-earliest', 'Koten, D.', 2023],
            ['ref-later', 'Koten, D.', 2023],
        ]),
    );

    expect($resolution->state)->toBe(CitationResolutionState::Unresolved)
        ->and($resolution->referenceId)->toBeNull()
        ->and($resolution->candidates[0]->referenceId)->toBe('ref-earliest')
        ->and($resolution->candidates[1]->referenceId)->toBe('ref-later');
});

it('uses initials to disambiguate same-surname authors', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, A., 2023)'),
        citationReferences([
            ['ref-daniel', 'Koten, D. B.', 2023],
            ['ref-andi', 'Koten, A.', 2023],
        ]),
    );

    expect($resolution->referenceId)->toBe('ref-andi');
});

it('orders candidates by their bibliography index regardless of input order', function () {
    $matcher = new AuthorMatcher(new StringSimilarity);

    $references = [
        new CitationReference('ref-b', $matcher->names('Koten, D.'), 2023, 2),
        new CitationReference('ref-a', $matcher->names('Koten, D.'), 2023, 1),
    ];

    $resolution = $this->resolver->resolve($this->parser->parse('(Koten, 2023)'), $references);

    expect($resolution->candidates[0]->referenceId)->toBe('ref-a')
        ->and($resolution->candidates[1]->referenceId)->toBe('ref-b');
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
        ->and($resolution->method)->toBe(CitationResolutionMethod::Ieee)
        ->and($resolution->confidence)->toBe(1.0);
});

it('leaves an out-of-range ieee ordinal unmatched', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('[99]'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
    );

    expect($resolution->state)->toBe(CitationResolutionState::Unmatched);
});

it('uses the lowest ordinal as primary and keeps the rest as candidates', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('[3-5]'),
        citationReferences([
            ['ref-1', 'A', 2000],
            ['ref-2', 'B', 2001],
            ['ref-3', 'C', 2002],
            ['ref-4', 'D', 2003],
        ]),
    );

    expect($resolution->referenceId)->toBe('ref-3')
        ->and($resolution->candidates)->toHaveCount(2)
        ->and($resolution->candidates[0]->referenceId)->toBe('ref-3')
        ->and($resolution->candidates[1]->referenceId)->toBe('ref-4');
});

it('leaves an unparseable marker unmatched', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('tanpa tahun'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
    );

    expect($resolution->state)->toBe(CitationResolutionState::Unmatched);
});

it('penalizes a cited author the reference does not carry', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten & Tani, 2023)'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
    );

    expect($resolution->state)->toBe(CitationResolutionState::Unresolved);
});

it('does not penalize an et al. citation with fewer surnames than the reference', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(LeCun et al., 2015)'),
        citationReferences([['ref-1', 'LeCun, Y., Bengio, Y., & Hinton, G.', 2015]]),
    );

    expect($resolution->referenceId)->toBe('ref-1');
});

it('trusts a validated extraction hint for an unparseable marker', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('tanpa penanda'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
        'ref-1',
        0,
    );

    expect($resolution->state)->toBe(CitationResolutionState::Paired)
        ->and($resolution->referenceId)->toBe('ref-1')
        ->and($resolution->method)->toBe(CitationResolutionMethod::ExtractionHint)
        ->and($resolution->confidence)->toBeNull()
        ->and($resolution->hintIndex)->toBe(0);
});

it('trusts a hint that agrees with the parser', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
        'ref-1',
        0,
    );

    expect($resolution->referenceId)->toBe('ref-1')
        ->and($resolution->method)->toBe(CitationResolutionMethod::ExtractionHint);
});

it('lets the parser win when it contradicts the hint', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([
            ['ref-1', 'LeCun, Y.', 2015],
            ['ref-2', 'Koten, D.', 2023],
        ]),
        'ref-1',
        0,
    );

    expect($resolution->referenceId)->toBe('ref-2')
        ->and($resolution->method)->toBe(CitationResolutionMethod::Apa);
});

it('ignores an invalid hint reference id', function () {
    $resolution = $this->resolver->resolve(
        $this->parser->parse('(Koten, 2023)'),
        citationReferences([['ref-1', 'Koten, D.', 2023]]),
        'ref-missing',
        0,
    );

    expect($resolution->referenceId)->toBe('ref-1')
        ->and($resolution->method)->toBe(CitationResolutionMethod::Apa);
});
