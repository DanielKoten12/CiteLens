<?php

namespace App\Data\ResearchedDocument;

use App\Data\BaseData;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Derived analysis counts for one document (`docs/API_SPEC.md` §4).
 *
 * `total_references` is the number of bibliography entries; the four reference
 * buckets are finding counts. A completed document with an un-evaluated
 * reference can therefore have `total_references` greater than the sum of the
 * four buckets — `pending` has no bucket in the canonical summary.
 */
#[MapName(SnakeCaseMapper::class)]
final class DocumentAnalysisSummaryData extends BaseData
{
    public function __construct(
        public int $totalReferences,
        public int $valid,
        public int $suspicious,
        public int $invalid,
        public int $notFound,
        public int $totalCitations,
        public int $validCitations,
        public int $unreliableCitations,
        public int $pendingCitations,
        public int $hallucinationCitations,
    ) {}

    /**
     * Build the summary from the grouped aggregate counts.
     */
    public static function forCounts(
        int $totalReferences,
        int $valid,
        int $suspicious,
        int $invalid,
        int $notFound,
        int $totalCitations,
        int $validCitations,
        int $unreliableCitations,
        int $pendingCitations,
        int $hallucinationCitations,
    ): self {
        return new self(
            totalReferences: $totalReferences,
            valid: $valid,
            suspicious: $suspicious,
            invalid: $invalid,
            notFound: $notFound,
            totalCitations: $totalCitations,
            validCitations: $validCitations,
            unreliableCitations: $unreliableCitations,
            pendingCitations: $pendingCitations,
            hallucinationCitations: $hallucinationCitations,
        );
    }
}
