<?php

use App\Services\Crossref\DoiNormalizer;

beforeEach(function () {
    $this->normalizer = new DoiNormalizer;
});

it('strips DOI URL and prefix noise and lowercases', function () {
    expect($this->normalizer->normalize('https://doi.org/10.1038/NATURE14539'))->toBe('10.1038/nature14539')
        ->and($this->normalizer->normalize('http://dx.doi.org/10.1038/nature14539'))->toBe('10.1038/nature14539')
        ->and($this->normalizer->normalize('doi:10.1038/nature14539'))->toBe('10.1038/nature14539')
        ->and($this->normalizer->normalize('DOI: 10.1038/nature14539 '))->toBe('10.1038/nature14539')
        ->and($this->normalizer->normalize('10.1038/nature14539.'))->toBe('10.1038/nature14539');
});

it('decodes percent-encoded DOIs', function () {
    expect($this->normalizer->normalize('10.1000/something%20encoded'))->toBe('10.1000/something encoded');
});

it('returns null only when no DOI value is present', function () {
    expect($this->normalizer->normalize(null))->toBeNull()
        ->and($this->normalizer->normalize(''))->toBeNull()
        ->and($this->normalizer->normalize('   '))->toBeNull();
});

it('preserves a malformed DOI so it can be classified as invalid later', function () {
    expect($this->normalizer->normalize('doi:not-a-doi'))->toBe('not-a-doi')
        ->and($this->normalizer->isValid('not-a-doi'))->toBeFalse();
});

it('validates the canonical DOI shape', function () {
    expect($this->normalizer->isValid('10.1038/nature14539'))->toBeTrue()
        ->and($this->normalizer->isValid('10.1234/abc-def_ghi'))->toBeTrue()
        ->and($this->normalizer->isValid('10.123/abc'))->toBeFalse()
        ->and($this->normalizer->isValid('10.12345'))->toBeFalse()
        ->and($this->normalizer->isValid('https://doi.org/10.1038/nature14539'))->toBeFalse();
});
