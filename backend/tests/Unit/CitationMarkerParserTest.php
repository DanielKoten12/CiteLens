<?php

use App\Services\Citations\CitationMarkerParser;
use App\Services\Citations\ParsedCitationMarker;
use App\Services\Scoring\AuthorMatcher;
use App\Services\Scoring\StringSimilarity;

beforeEach(function () {
    $this->parser = new CitationMarkerParser(new AuthorMatcher(new StringSimilarity));
});

it('parses a parenthetical apa marker', function () {
    $marker = $this->parser->parse('(Koten, 2023)');

    expect($marker->surnames)->toBe(['Koten'])
        ->and($marker->year)->toBe(2023)
        ->and($marker->ordinals)->toBe([])
        ->and($marker->isApa())->toBeTrue()
        ->and($marker->isParsed())->toBeTrue();
});

it('parses narrative apa markers', function () {
    expect($this->parser->parse('Koten et al. (2023)')->surnames)->toBe(['Koten'])
        ->and($this->parser->parse('Koten et al. (2023)')->year)->toBe(2023)
        ->and($this->parser->parse('Koten (2023)')->surnames)->toBe(['Koten']);
});

it('parses multiple surnames joined by ampersand', function () {
    $marker = $this->parser->parse('(Koten & Tani, 2023)');

    expect($marker->surnames)->toBe(['Koten', 'Tani'])
        ->and($marker->year)->toBe(2023);
});

it('parses a marker without enclosing parentheses', function () {
    $marker = $this->parser->parse('LeCun et al., 2015');

    expect($marker->surnames)->toBe(['LeCun'])
        ->and($marker->year)->toBe(2015);
});

it('falls back to the citation text when the marker is missing', function () {
    $marker = $this->parser->parse(null, '(Tani, 2021)');

    expect($marker->surnames)->toBe(['Tani'])
        ->and($marker->year)->toBe(2021);

    $blank = $this->parser->parse('   ', '(Tani, 2021)');

    expect($blank->surnames)->toBe(['Tani']);
});

it('parses ieee ordinals', function () {
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
        ->and($marker->surnames)->toBe([]);
});

it('reduces a multi-reference parenthetical to the first pair', function () {
    $marker = $this->parser->parse('(Koten, 2023; Tani, 2021)');

    expect($marker->surnames)->toBe(['Koten'])
        ->and($marker->year)->toBe(2023);
});

it('keeps only the first pair of a narrative multi-reference marker', function () {
    $marker = $this->parser->parse('Koten (2023); Tani (2021)');

    expect($marker->surnames)->toBe(['Koten'])
        ->and($marker->year)->toBe(2023);
});

it('returns an empty marker for unparseable input', function () {
    foreach ([null, '', '   ', 'n.d.', '[]', 'et al.'] as $input) {
        $marker = $this->parser->parse($input);

        expect($marker)->toBeInstanceOf(ParsedCitationMarker::class)
            ->and($marker->isParsed())->toBeFalse();
    }
});
