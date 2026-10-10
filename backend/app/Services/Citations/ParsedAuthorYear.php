<?php

namespace App\Services\Citations;

use App\Services\Scoring\AuthorName;

/**
 * One APA author/year pair parsed from a citation marker.
 *
 * A marker may carry several pairs (`(A, 2020; B, 2021)`); the first pair is the
 * primary one, the rest are retained so their references can be offered as
 * candidates (Phase 05.1).
 */
final class ParsedAuthorYear
{
    /**
     * @param  list<AuthorName>  $authors
     */
    public function __construct(
        public readonly array $authors = [],
        public readonly ?int $year = null,
    ) {}

    /**
     * A stable grouping key: normalized surnames (order-insensitive) + year.
     */
    public function key(): string
    {
        $surnames = array_map(
            static fn (AuthorName $author): string => mb_strtolower($author->surname),
            $this->authors,
        );

        sort($surnames);

        return implode('|', $surnames).'|'.($this->year ?? '');
    }

    /**
     * A copy with missing evidence filled in from a sibling pair.
     */
    public function mergedWith(?int $year): self
    {
        return new self($this->authors, $this->year ?? $year);
    }
}
