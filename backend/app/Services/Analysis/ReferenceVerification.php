<?php

namespace App\Services\Analysis;

use App\Services\Crossref\CrossrefWorkData;
use App\Services\Crossref\DoiLookup;
use App\Services\Scoring\ScoringReference;

/**
 * Transient Crossref retrieval result for one reference, produced by
 * {@see Steps\ValidateReferencesStep} and consumed by the embedding/scoring
 * steps.
 *
 * Nothing here is persisted directly: `ScoreReferencesStep` converts the batch
 * into findings/candidates through `ReferenceFindingWriter`.
 */
final class ReferenceVerification
{
    /**
     * @param  list<CrossrefWorkData>  $candidates
     */
    public function __construct(
        public readonly ScoringReference $reference,
        public readonly DoiLookup $doiLookup,
        public readonly array $candidates,
        public readonly bool $transientFailure = false,
    ) {}
}
