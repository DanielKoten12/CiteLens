<?php

namespace App\Services\Analysis;

/**
 * Mutable per-run state for `ValidateReferencesStep`.
 *
 * Kept out of the step instance so state can never leak between documents when
 * the container reuses a step instance.
 */
final class VerificationRunState
{
    /**
     * At least one primary-source exchange completed (a DOI hit/miss or a
     * search response), which turns a later outage into a per-reference
     * degradation instead of a fatal error.
     */
    public bool $primaryResponded = false;

    /**
     * At least one Garuda exchange completed, including `Found 0 documents`.
     */
    public bool $garudaResponded = false;

    /**
     * Garuda lookups attempted for this document (cache hits included).
     */
    public int $garudaLookups = 0;
}
