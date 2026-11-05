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
 * empty list; a source that cannot answer throws its own exception and the
 * caller applies that source's outage policy.
 *
 * DOI lookup is deliberately not part of this contract — only Crossref resolves
 * DOIs (`CrossrefClient::findByDoi()`).
 */
interface BibliographicSource
{
    public function source(): MetadataSource;

    /**
     * @return list<WorkMetadata>
     */
    public function search(ReferenceQuery $query): array;
}
