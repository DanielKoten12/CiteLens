<?php

namespace App\Services\Citations;

/**
 * Outcome of resolving one citation marker against a document's references.
 *
 * The schema has no citation confidence/reason column, so `confidence` and
 * `matchReason` are diagnostics only (tie-breaking and logging); only the
 * `referenceId` is ever persisted.
 */
final class CitationResolution
{
    public function __construct(
        public readonly ?string $referenceId,
        public readonly ?float $confidence = null,
        public readonly ?string $matchReason = null,
    ) {}

    public function isPaired(): bool
    {
        return $this->referenceId !== null;
    }

    public static function unpaired(?string $reason = null): self
    {
        return new self(null, null, $reason);
    }
}
