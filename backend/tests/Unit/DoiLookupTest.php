<?php

use App\Services\Metadata\DoiLookup;

it('pins the internal doi lookup values', function () {
    expect(array_column(DoiLookup::cases(), 'value'))
        ->toBe(['not_present', 'malformed', 'resolved', 'not_found', 'unknown']);
});
