<?php

namespace App\Enums;

/**
 * Type of an item in the derived findings/highlights feed
 * (`GET /documents/{document}/findings`, `docs/API_SPEC.md` §7).
 *
 * OQ-09 resolved: `reference_pending` is part of the feed (severity `info`).
 */
enum FindingType: string
{
    case ReferenceInvalid = 'reference_invalid';
    case ReferenceSuspicious = 'reference_suspicious';
    case ReferenceNotFound = 'reference_not_found';
    case ReferencePending = 'reference_pending';
    case CitationUnreliable = 'citation_unreliable';
    case CitationUnresolved = 'citation_unresolved';
    case CitationHallucination = 'citation_hallucination';

    /**
     * Whether this finding originates from a bibliography reference.
     */
    public function isReference(): bool
    {
        return str_starts_with($this->value, 'reference_');
    }

    /**
     * Whether this finding originates from an in-text citation.
     */
    public function isCitation(): bool
    {
        return str_starts_with($this->value, 'citation_');
    }
}
