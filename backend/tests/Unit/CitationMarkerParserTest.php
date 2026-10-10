<?php

use App\Services\Citations\CitationMarkerParser;
use App\Services\Citations\ParsedAuthorYear;
use App\Services\Scoring\AuthorMatcher;
use App\Services\Scoring\StringSimilarity;

beforeEach(function () {
    $this->parser = new CitationMarkerParser(new AuthorMatcher(new StringSimilarity));
});

/**
 * @return list<string>
 */
function parsedSurnames(?ParsedAuthorYear $pair): array
{
    if ($pair === null) {
        return [];
    }

    return array_map(static fn ($author): string => $author->surname, $pair->authors);
}

it('parses a parenthetical apa marker', function () {
    $marker = $this->parser->parse('(Koten, 2023)');

    expect($marker->pairs)->toHaveCount(1)
        ->and(parsedSurnames($marker->primaryPair()))->toBe(['Koten'])
        ->and($marker->primaryPair()->year)->toBe(2023)
        ->and($marker->ordinals)->toBe([])
        ->and($marker->isApa())->toBeTrue()
        ->and($marker->isParsed())->toBeTrue();
});

it('captures initials from a citation marker', function () {
    $pair = $this->parser->parse('(Koten, D. B., 2023)')->primaryPair();

    expect(parsedSurnames($pair))->toBe(['Koten'])
        ->and($pair->authors[0]->initials)->toBe('DB')
        ->and($pair->authors[0]->firstInitial())->toBe('D')
        ->and($pair->year)->toBe(2023);
});

it('parses narrative apa markers', function () {
    expect(parsedSurnames($this->parser->parse('Koten et al. (2023)')->primaryPair()))->toBe(['Koten'])
        ->and($this->parser->parse('Koten et al. (2023)')->primaryPair()->year)->toBe(2023)
        ->and(parsedSurnames($this->parser->parse('Koten (2023)')->primaryPair()))->toBe(['Koten']);
});

it('parses multiple surnames joined by ampersand', function () {
    $marker = $this->parser->parse('(Koten & Tani, 2023)');

    expect(parsedSurnames($marker->primaryPair()))->toBe(['Koten', 'Tani'])
        ->and($marker->primaryPair()->year)->toBe(2023);
});

it('parses a marker without enclosing parentheses', function () {
    $marker = $this->parser->parse('LeCun et al., 2015');

    expect(parsedSurnames($marker->primaryPair()))->toBe(['LeCun'])
        ->and($marker->primaryPair()->year)->toBe(2015);
});

it('falls back to the citation text when the marker is missing', function () {
    $marker = $this->parser->parse(null, '(Tani, 2021)');

    expect(parsedSurnames($marker->primaryPair()))->toBe(['Tani'])
        ->and($marker->primaryPair()->year)->toBe(2021);

    $blank = $this->parser->parse('   ', '(Tani, 2021)');

    expect(parsedSurnames($blank->primaryPair()))->toBe(['Tani']);
});

it('parses every ieee ordinal', function () {
    expect($this->parser->parse('[3]')->ordinals)->toBe([3])
        ->and($this->parser->parse('[3], [5]')->ordinals)->toBe([3, 5])
        ->and($this->parser->parse('[3-5]')->ordinals)->toBe([3, 4, 5])
        ->and($this->parser->parse('[3–5]')->ordinals)->toBe([3, 4, 5])
        ->and($this->parser->parse('[5, 3]')->ordinals)->toBe([3, 5]);
});

it('prefers an ieee classification when brackets are present', function () {
    $marker = $this->parser->parse('[3] (Koten, 2023)');

    expect($marker->isIeee())->toBeTrue()
        ->and($marker->ordinals)->toBe([3])
        ->and($marker->pairs)->toBe([]);
});

it('retains every pair of a multi-reference parenthetical', function () {
    $marker = $this->parser->parse('(Koten, 2023; Tani, 2021)');

    expect($marker->pairs)->toHaveCount(2)
        ->and(parsedSurnames($marker->pairs[0]))->toBe(['Koten'])
        ->and($marker->pairs[0]->year)->toBe(2023)
        ->and(parsedSurnames($marker->pairs[1]))->toBe(['Tani'])
        ->and($marker->pairs[1]->year)->toBe(2021);
});

it('retains every pair of a narrative multi-reference marker', function () {
    $marker = $this->parser->parse('Koten (2023); Tani (2021)');

    expect($marker->pairs)->toHaveCount(2)
        ->and(parsedSurnames($marker->pairs[0]))->toBe(['Koten'])
        ->and(parsedSurnames($marker->pairs[1]))->toBe(['Tani']);
});

it('deduplicates identical pairs', function () {
    $marker = $this->parser->parse('(Koten, 2023; Koten, 2023)');

    expect($marker->pairs)->toHaveCount(1);
});

it('returns an empty marker for unparseable input', function () {
    foreach ([null, '', '   ', 'n.d.', '[]', 'et al.'] as $input) {
        $marker = $this->parser->parse($input);

        expect($marker->isParsed())->toBeFalse();
    }
});
