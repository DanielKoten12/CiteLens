<?php

namespace App\Services\Citations;

use App\Services\Scoring\AuthorMatcher;
use App\Services\Scoring\AuthorName;

/**
 * A bibliography reference as seen by the citation resolver.
 *
 * A pure value object: the pipeline step maps Eloquent models to this shape
 * (parsing authors with {@see AuthorMatcher::names()})
 * so the resolver never touches the database. `bibliographyIndex` is the 1-based
 * OQ-18 order (`text_start_offset` ASC, nulls last, `id` tiebreak) used to
 * resolve IEEE ordinals.
 */
final class CitationReference
{
    /**
     * @param  list<AuthorName>  $authors
     */
    public function __construct(
        public readonly string $id,
        public readonly array $authors,
        public readonly ?int $publicationYear,
        public readonly int $bibliographyIndex,
    ) {}
}
