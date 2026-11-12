<?php

namespace App\Services\Analysis;

use App\Enums\MetadataSource;
use App\Services\Metadata\DoiLookup;
use App\Services\Metadata\WorkMetadata;
use App\Services\Scoring\ScoringReference;

/**
 * Transient metadata retrieval result for one reference, produced by
 * {@see Steps\ValidateReferencesStep} and consumed by the embedding/scoring
 * steps.
 *
 * Nothing here is persisted directly: `ScoreReferencesStep` converts the batch
 * into findings/candidates through `ReferenceFindingWriter`.
 */
final class ReferenceVerification
{
    /**
     * @param  list<WorkMetadata>  $candidates
     * @param  MetadataSource|null  $doiSource  resolver that confirmed the DOI, if any
     */
    public function __construct(
        public readonly ScoringReference $reference,
        public readonly DoiLookup $doiLookup,
        public readonly array $candidates,
        public readonly bool $transientFailure = false,
        public readonly ?string $pendingReason = null,
        public readonly ?MetadataSource $doiSource = null,
    ) {}
}
