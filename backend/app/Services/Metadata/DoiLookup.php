<?php

namespace App\Services\Metadata;

/**
 * Outcome of the DOI resolution chain for one reference, before the verdict is
 * decided.
 *
 * Internal to the verification pipeline (not an API enum); the public verdict
 * is `ReferenceFindingStatus`. `NotPresent` and `Malformed` never reach a
 * resolver. The remaining outcomes are:
 *
 * - `Resolved`: an enabled resolver returned a work for the DOI.
 * - `NotFound`: every enabled resolver completed a definitive miss (`404`).
 * - `Unknown`: at least one enabled resolver could not answer (outage, circuit
 *   breaker or budget) and none resolved. This must become `pending`, never
 *   `invalid`: "we could not check" is not "the DOI is broken" (D-09-16).
 */
enum DoiLookup: string
{
    case NotPresent = 'not_present';
    case Malformed = 'malformed';
    case Resolved = 'resolved';
    case NotFound = 'not_found';
    case Unknown = 'unknown';
}
