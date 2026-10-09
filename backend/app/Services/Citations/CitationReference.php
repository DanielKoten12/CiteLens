<?php

namespace App\Services\Citations;

/**
 * A bibliography reference as seen by the citation resolver.
 *
 * A pure value object: the pipeline step maps Eloquent models to this shape so
 * the resolver never touches the database. `bibliographyIndex` is the 1-based
 * OQ-18 order (`text_start_offset` ASC, nulls last, `id` tiebreak) used to
 * resolve IEEE ordinals.
 */
final class CitationReference
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $authors,
        public readonly ?int $publicationYear,
        public readonly int $bibliographyIndex,
    ) {}
}
