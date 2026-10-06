<?php

namespace App\Enums;

/**
 * Derived citation status — never persisted (`docs/API_SPEC.md` §2.6).
 *
 * The single implementation of the derivation rule lives in {@see self::derive()};
 * every endpoint, filter and summary must call it instead of re-implementing the table.
 */
enum CitationStatus: string
{
    case Valid = 'valid';
    case Unreliable = 'unreliable';
    case Pending = 'pending';
    case Hallucination = 'hallucination';

    /**
     * Derive the citation status from its pairing and the paired reference finding.
     *
     * A paired citation whose reference has no finding yet is still `pending`.
     */
    public static function derive(bool $isPaired, ?ReferenceFindingStatus $findingStatus): self
    {
        return match (true) {
            ! $isPaired => self::Hallucination,
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
            self::Hallucination => FindingType::CitationHallucination,
            self::Valid, self::Pending => null,
        };
    }
}
