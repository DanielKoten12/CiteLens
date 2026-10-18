<?php

namespace App\Services\Citations;

/**
 * One citation to resolve in a batch run: its id, parsed marker and (validated)
 * extraction hint.
 */
final class CitationResolutionInput
{
    public function __construct(
        public readonly string $citationId,
        public readonly ParsedCitationMarker $marker,
        public readonly ?string $hintedReferenceId = null,
        public readonly ?int $hintIndex = null,
    ) {}
}
