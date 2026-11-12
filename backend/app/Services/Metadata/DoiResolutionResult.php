<?php

namespace App\Services\Metadata;

use App\Enums\MetadataSource;

/**
 * Immutable outcome of the DOI resolution chain for one reference.
 *
 * `source` is the resolver that answered (`Resolved`) or `null`. When
 * `lookup` is `Unknown`, `pendingReason` carries the DOI-specific transient
 * reason; candidates found by the search chain are still persisted so a human
 * reviewer keeps the evidence (D-09-16).
 */
final class DoiResolutionResult
{
    public function __construct(
        public readonly DoiLookup $lookup,
        public readonly ?WorkMetadata $work = null,
        public readonly ?MetadataSource $source = null,
        public readonly ?string $pendingReason = null,
    ) {}
}
