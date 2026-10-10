<?php

namespace App\Enums;

/**
 * Verification verdict stored per bibliography reference
 * (`reference_findings.status`).
 *
 * Canonical values: `docs/API_SPEC.md` §2.6.
 */
enum ReferenceFindingStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Suspicious = 'suspicious';
    case Invalid = 'invalid';
    case NotFound = 'not_found';

    /**
     * Indonesian label for human-facing surfaces (report template).
     *
     * The API keeps the machine value; only the Blade view renders labels (D-06-08).
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu penilaian',
            self::Valid => 'Valid',
            self::Suspicious => 'Perlu ditinjau',
            self::Invalid => 'Tidak valid',
            self::NotFound => 'Tidak ditemukan',
        };
    }

    /**
     * Whether this status surfaces a problem in the findings feed.
     */
    public function isProblem(): bool
    {
        return match ($this) {
            self::Invalid, self::NotFound, self::Suspicious => true,
            self::Pending, self::Valid => false,
        };
    }

    /**
     * The findings-feed type for this status, or null when the status is not a finding.
     */
    public function toFindingType(): ?FindingType
    {
        return match ($this) {
            self::Suspicious => FindingType::ReferenceSuspicious,
            self::Invalid => FindingType::ReferenceInvalid,
            self::NotFound => FindingType::ReferenceNotFound,
            self::Pending => FindingType::ReferencePending,
            self::Valid => null,
        };
    }

    /**
     * The static severity for this status, or null when the status is not a finding.
     *
     * `citation_unreliable` severity depends on the paired reference finding and is
     * composed in the findings resolver, not here.
     */
    public function toSeverity(): ?FindingSeverity
    {
        return match ($this) {
            self::Invalid, self::NotFound => FindingSeverity::High,
            self::Suspicious => FindingSeverity::Medium,
            self::Pending => FindingSeverity::Info,
            self::Valid => null,
        };
    }
}
