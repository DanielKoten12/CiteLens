<?php

namespace App\Services\Analysis;

/**
 * Transient per-run mapping from the extraction payload to persisted rows.
 *
 * `reference_index` in the GROBID contract is a 0-based index into the payload's
 * references array; the persistence step assigns the real (persisted) reference
 * ids in payload order, so only this artifact can translate an index into a
 * reference id reliably (offsets may be missing). The same payload carries the
 * per-citation hint, keyed by the persisted citation id.
 *
 * GROBID provides no confidence for the hint; the resolver validates it against
 * the parsed marker instead (D-05.1-03).
 */
final class CitationExtractionHints
{
    /**
     * @param  array<int, string>  $referenceIdsByIndex  0-based payload index → persisted reference id
     * @param  array<string, int|null>  $hintByCitationId  persisted citation id → GROBID reference_index
     */
    public function __construct(
        public readonly array $referenceIdsByIndex = [],
        public readonly array $hintByCitationId = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function referenceIdForIndex(?int $index): ?string
    {
        if ($index === null) {
            return null;
        }

        return $this->referenceIdsByIndex[$index] ?? null;
    }

    public function hintForCitation(string $citationId): ?int
    {
        return $this->hintByCitationId[$citationId] ?? null;
    }
}
