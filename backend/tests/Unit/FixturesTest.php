<?php

use Tests\Support\Fixtures;

it('loads a recorded crossref payload', function () {
    $payload = Fixtures::json('crossref/doi-found');

    expect($payload['status'])->toBe('ok')
        ->and($payload['message']['DOI'])->toBe('10.1038/nature14539')
        ->and($payload['message']['title'][0])->toBe('Deep learning')
        ->and($payload['message']['issued']['date-parts'][0][0])->toBe(2015);
});

it('accepts a path with the json extension', function () {
    expect(Fixtures::json('crossref/doi-found.json'))->toBe(Fixtures::json('crossref/doi-found'));
});

it('fails loudly for a missing fixture', function () {
    expect(fn () => Fixtures::json('crossref/does-not-exist'))
        ->toThrow(RuntimeException::class, 'Fixture not found: crossref/does-not-exist');
});
