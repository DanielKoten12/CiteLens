<?php

namespace App\Services\Metadata;

use App\Enums\MetadataSource;

/**
 * Immutable outcome of the metadata search chain for one reference.
 *
 * `transientFailure` means no candidate was found **and** at least one enabled
 * source could not answer or was skipped by budget. That must become `pending`,
 * never `not_found` (D-09-04): `not_found` means every enabled source answered
 * and none matched.
 */
final class SourceChainResult
{
    /**
     * @param  list<WorkMetadata>  $candidates  merged, source-order deduplicated
     * @param  list<MetadataSource>  $consultedSources
     */
    public function __construct(
        public readonly array $candidates = [],
        public readonly bool $transientFailure = false,
        public readonly ?string $pendingReason = null,
        public readonly array $consultedSources = [],
    ) {}
}
