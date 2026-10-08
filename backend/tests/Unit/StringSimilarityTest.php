<?php

use App\Services\Scoring\StringSimilarity;

beforeEach(function () {
    $this->strings = new StringSimilarity;
});

it('normalizes text for comparison', function () {
    expect($this->strings->normalize('  Deep   Learning! '))->toBe('deep learning')
        ->and($this->strings->normalize('Müller'))->toBe('müller')
        ->and($this->strings->normalize('Koten & Tani'))->toBe('koten tani')
        ->and($this->strings->normalize(null))->toBe('')
        ->and($this->strings->normalize('   '))->toBe('');
});

it('computes normalized levenshtein for known pairs', function () {
    // T-SCORE-01
    expect($this->strings->levenshtein('kitten', 'sitting'))->toEqualWithDelta(1 - 3 / 7, 0.0001)
        ->and($this->strings->levenshtein('Nature', 'Natur'))->toEqualWithDelta(1 - 1 / 6, 0.0001)
        ->and($this->strings->levenshtein('abc', 'abc'))->toBe(1.0)
        ->and($this->strings->levenshtein('abc', 'xyz'))->toBe(0.0);
});

it('returns null when either input is empty', function () {
    expect($this->strings->levenshtein(null, 'abc'))->toBeNull()
        ->and($this->strings->levenshtein('abc', ''))->toBeNull()
        ->and($this->strings->levenshtein('', ''))->toBeNull();
});

it('scores the author typo case high with jaro-winkler', function () {
    // T-SCORE-02
    expect($this->strings->jaroWinkler('Koten', 'Koton'))->toBeGreaterThan(0.85);
});

it('is unicode aware for levenshtein and jaro-winkler', function () {
    expect($this->strings->levenshtein('Koten', 'Kotén'))->toEqualWithDelta(0.8, 0.0001)
        ->and($this->strings->jaroWinkler('Müller', 'Mueller'))->toBeGreaterThan(0.5);
});

it('returns null for empty jaro-winkler inputs', function () {
    expect($this->strings->jaroWinkler(null, 'abc'))->toBeNull()
        ->and($this->strings->jaroWinkler('abc', ''))->toBeNull();
});
