<?php

namespace App\Services\Crossref;

/**
 * One publication record returned by Crossref, normalized to the fields the
 * verification pipeline needs (`docs/API_SPEC.md` §10).
 *
 * Pure value object: no Eloquent, no container. `doi` is already canonical
 * (lowercased, no URL prefix) because the mapper runs it through
 * {@see DoiNormalizer}. Any field may be `null` — Crossref metadata is
 * inconsistent, and the scorer treats a missing field as a missing signal
 * rather than a zero.
 */
final class CrossrefWorkData
{
    /**
     * @param  list<string>  $authors  one "Given Family" string per author
     */
    public function __construct(
        public readonly ?string $doi,
        public readonly ?string $title,
        public readonly array $authors = [],
        public readonly ?string $containerTitle = null,
        public readonly ?int $publicationYear = null,
        public readonly ?string $url = null,
        public readonly ?string $type = null,
    ) {}

    /**
     * Authors as a single storable string (`reference_finding_candidates.authors`).
     */
    public function authorString(): ?string
    {
        return $this->authors === [] ? null : implode(', ', $this->authors);
    }

    /**
     * Whether there is anything to compare against a reference.
     */
    public function hasBibliographicData(): bool
    {
        return ($this->title !== null && trim($this->title) !== '')
            || $this->authors !== [];
    }
}
