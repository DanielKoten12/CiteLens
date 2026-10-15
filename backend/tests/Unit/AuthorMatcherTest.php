<?php

use App\Services\Scoring\AuthorMatcher;
use App\Services\Scoring\StringSimilarity;

beforeEach(function () {
    $this->authors = new AuthorMatcher(new StringSimilarity);
});

it('parses an apa author string into surnames', function () {
    expect($this->authors->surnames('LeCun, Y., Bengio, Y., & Hinton, G.'))
        ->toBe(['LeCun', 'Bengio', 'Hinton']);
});

it('parses a single author with initials and a given-first name', function () {
    expect($this->authors->surnames('Koten, D. B.'))->toBe(['Koten'])
        ->and($this->authors->surnames('Yann LeCun'))->toBe(['LeCun'])
        ->and($this->authors->surnames('LeCun Y.'))->toBe(['LeCun'])
        ->and($this->authors->surnames('Koten et al.'))->toBe(['Koten']);
});

it('returns an empty list when there is nothing to parse', function () {
    expect($this->authors->surnames(null))->toBe([])
        ->and($this->authors->surnames(''))->toBe([])
        ->and($this->authors->surnames('   '))->toBe([]);
});

it('parses structured author names with initials', function () {
    $names = $this->authors->names('LeCun, Y., Bengio, Y., & Hinton, G.');

    expect(array_map(static fn ($name): string => $name->surname, $names))->toBe(['LeCun', 'Bengio', 'Hinton'])
        ->and($names[0]->initials)->toBe('Y')
        ->and($names[0]->firstInitial())->toBe('Y');

    $koten = $this->authors->names('Koten, D. B.')[0];

    expect($koten->surname)->toBe('Koten')
        ->and($koten->initials)->toBe('DB')
        ->and($koten->firstInitial())->toBe('D');

    $givenFirst = $this->authors->names('Yann LeCun')[0];

    expect($givenFirst->surname)->toBe('LeCun')
        ->and($givenFirst->initials)->toBe('Y');

    $trailing = $this->authors->names('LeCun Y.')[0];

    expect($trailing->surname)->toBe('LeCun')
        ->and($trailing->initials)->toBe('Y');
});

it('matches authors order-insensitively', function () {
    $reference = 'LeCun, Y., Bengio, Y., & Hinton, G.';
    $candidate = ['Yann LeCun', 'Yoshua Bengio', 'Geoffrey Hinton'];

    $forward = $this->authors->similarity($reference, $candidate);
    $reversed = $this->authors->similarity($reference, array_reverse($candidate));

    expect($forward)->toBeGreaterThan(0.95)
        ->and($reversed)->toEqualWithDelta($forward, 0.0001);
});

it('scores a surname typo high', function () {
    expect($this->authors->similarity('Koten, D.', ['Daniel Koton']))->toBeGreaterThan(0.8);
});

it('returns null when either side has no author', function () {
    expect($this->authors->similarity(null, ['Yann LeCun']))->toBeNull()
        ->and($this->authors->similarity('LeCun, Y.', []))->toBeNull();
});

it('mildly penalizes a materially different author count', function () {
    $equal = $this->authors->similarity('LeCun, Y.', ['Yann LeCun']);
    $truncated = $this->authors->similarity('LeCun, Y.', ['Yann LeCun', 'Yoshua Bengio', 'Geoffrey Hinton', 'Alice Zhang']);

    expect($truncated)->toBeLessThan($equal);
});
