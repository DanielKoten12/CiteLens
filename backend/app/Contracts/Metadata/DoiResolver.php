<?php

namespace App\Contracts\Metadata;

use App\Enums\MetadataSource;
use App\Services\Metadata\WorkMetadata;

/**
 * A source that can resolve a normalized DOI to its publication record.
 *
 * DOI resolution is a separate capability from bibliographic search: Garuda
 * publishes no API and implements {@see BibliographicSource} only, while
 * Crossref, Semantic Scholar and OpenAlex implement both. A resolver returning
 * `null` is a **definitive miss** for that resolver; a resolver that cannot
 * answer throws its own {@see SourceUnavailableException} and the chain applies
 * that source's outage policy.
 */
interface DoiResolver
{
    public function source(): MetadataSource;

    /**
     * Whether the source is configured and usable. A disabled source is skipped
     * by the chain and never turns a completed miss into an outage.
     */
    public function enabled(): bool;

    /**
     * Resolve a normalized DOI (lowercase, no URL prefix).
     *
     * @return WorkMetadata|null the work, or `null` on a definitive `404` miss
     */
    public function resolve(string $normalizedDoi): ?WorkMetadata;
}
