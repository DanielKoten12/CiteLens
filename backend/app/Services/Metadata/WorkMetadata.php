<?php

namespace App\Services\Metadata;

use App\Enums\MetadataSource;

/**
 * One publication record returned by an external metadata source, normalized
 * to the fields the verification pipeline needs (`docs/API_SPEC.md` §10).
 *
 * Pure value object: no Eloquent, no container. `doi` is already canonical
 * (lowercased, no URL prefix) because each source mapper runs it through
 * {@see DoiNormalizer}. Any field may be `null` — source metadata is
 * inconsistent, and the scorer treats a missing field as a missing signal
 * rather than a zero.
 */
final class WorkMetadata
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
        public readonly MetadataSource $source = MetadataSource::Crossref,
    ) {}

    /**
     * Same record with a canonical DOI.
     *
     * Used by the DOI chain: the endpoint is keyed by DOI, so the normalized
     * input is proof of resolution even when a mapper omits the field.
     */
    public function withDoi(?string $doi): self
    {
        return new self(
            doi: $doi,
            title: $this->title,
            authors: $this->authors,
            containerTitle: $this->containerTitle,
            publicationYear: $this->publicationYear,
            url: $this->url,
            type: $this->type,
            source: $this->source,
        );
    }

    /**
     * Same record with only the missing fields filled from another record.
     *
     * `$other` never overwrites a non-null field, and the source of `$this` is
     * preserved: provenance belongs to the record whose identity was kept
     * (D-09-09).
     */
    public function mergeMissing(self $other): self
    {
        return new self(
            doi: $this->doi ?? $other->doi,
            title: $this->title ?? $other->title,
            authors: $this->authors === [] ? $other->authors : $this->authors,
            containerTitle: $this->containerTitle ?? $other->containerTitle,
            publicationYear: $this->publicationYear ?? $other->publicationYear,
            url: $this->url ?? $other->url,
            type: $this->type ?? $other->type,
            source: $this->source,
        );
    }

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
