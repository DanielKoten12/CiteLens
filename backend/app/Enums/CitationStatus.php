<?php

namespace App\Enums;

/**
 * Derived citation status — never persisted (`docs/API_SPEC.md` §2.6).
 *
 * The single implementation of the derivation rule lives in {@see self::derive()};
 * every endpoint, filter and summary must call it instead of re-implementing the table.
 *
 * `unresolved` (Phase 05.1) means candidates exist but none cleared the commit
 * threshold; `hallucination` is reserved for "no plausible candidate exists".
 * The persisted `researched_document_citations.resolution_state` feeds the
 * derivation, so it stays computable from a query.
 */
enum CitationStatus: string
{
    case Valid = 'valid';
    case Unreliable = 'unreliable';
    case Pending = 'pending';
    case Unresolved = 'unresolved';
    case Hallucination = 'hallucination';

    /**
     * Derive the citation status from its resolution state and the paired
     * reference finding.
     */
    public static function derive(
        CitationResolutionState $state,
        ?ReferenceFindingStatus $findingStatus,
    ): self {
        return match (true) {
            $state === CitationResolutionState::Unresolved => self::Unresolved,
            $state === CitationResolutionState::Unmatched => self::Hallucination,
            $findingStatus === null, $findingStatus === ReferenceFindingStatus::Pending => self::Pending,
            $findingStatus === ReferenceFindingStatus::Valid,
            $findingStatus === ReferenceFindingStatus::Suspicious => self::Valid,
            default => self::Unreliable,
        };
    }

    /**
     * The findings-feed type for this status, or null when the status is not a finding.
     */
    public function toFindingType(): ?FindingType
    {
        return match ($this) {
            self::Unreliable => FindingType::CitationUnreliable,
            self::Unresolved => FindingType::CitationUnresolved,
            self::Hallucination => FindingType::CitationHallucination,
            self::Valid, self::Pending => null,
        };
    }

    /**
     * The finding severity for this status, or null when it is not a finding.
     *
     * `unreliable` inherits the paired reference finding's severity (always
     * `high` under the canonical mapping, but kept generic).
     */
    public function toSeverity(?ReferenceFindingStatus $findingStatus = null): ?FindingSeverity
    {
        return match ($this) {
            self::Unresolved => FindingSeverity::Medium,
            self::Hallucination => FindingSeverity::High,
            self::Unreliable => $findingStatus?->toSeverity() ?? FindingSeverity::High,
            self::Valid, self::Pending => null,
        };
    }
}
