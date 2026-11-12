<?php

namespace App\Contracts\Metadata;

use App\Enums\MetadataSource;
use App\Services\Metadata\ReferenceQuery;
use App\Services\Metadata\WorkMetadata;

/**
 * A source of candidate publication metadata for one bibliography entry.
 *
 * Implementations own transport and mapping only: they never rank, decide a
 * verdict or persist anything. A completed search with no results returns an
 * empty list; a source that cannot answer throws its own exception (implementing
 * {@see SourceUnavailableException}) and the caller applies that source's
 * outage policy.
 *
 * DOI lookup is a separate capability: see {@see DoiResolver}. A source may
 * implement both (Crossref, Semantic Scholar, OpenAlex) or search only (Garuda).
 */
interface BibliographicSource
{
    public function source(): MetadataSource;

    /**
     * Whether the source is configured and usable. Disabled sources are skipped
     * by the metadata chain and are not treated as an outage.
     */
    public function enabled(): bool;

    /**
     * @return list<WorkMetadata>
     */
    public function search(ReferenceQuery $query): array;
}
