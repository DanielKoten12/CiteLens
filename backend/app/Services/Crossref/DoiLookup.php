<?php

namespace App\Services\Crossref;

/**
 * Outcome of the DOI lookup for one reference, before the verdict is decided.
 *
 * Internal to the verification pipeline (not an API enum): the public verdict is
 * `ReferenceFindingStatus`. `NotPresent` and `Malformed` never reach Crossref;
 * `Resolved` and `NotFound` are the two possible outcomes of the HTTP lookup.
 */
enum DoiLookup: string
{
    case NotPresent = 'not_present';
    case Malformed = 'malformed';
    case Resolved = 'resolved';
    case NotFound = 'not_found';
}
