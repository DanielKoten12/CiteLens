<?php

namespace App\Enums;

/**
 * Resolved state of a citation's reference pairing
 * (`researched_document_citations.resolution_state`).
 *
 * - `paired`: a reference was selected confidently.
 * - `unresolved`: candidates exist but none cleared the commit threshold; the
 *   citation is deliberately left unpairable rather than forced (Phase 05.1).
 * - `unmatched`: no plausible candidate exists (a true hallucination).
 *
 * Persisted in Phase 05.1 W2; the enum is defined with the pure engine so the
 * value objects can carry it without a second definition later.
 */
enum CitationResolutionState: string
{
    case Paired = 'paired';
    case Unresolved = 'unresolved';
    case Unmatched = 'unmatched';
}
