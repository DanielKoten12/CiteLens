<?php

namespace App\Services\SemanticScholar;

use App\Services\Metadata\ReferenceQuery;
use App\Services\Scoring\StringSimilarity;

/**
 * Builds Semantic Scholar Graph API parameters and paths.
 *
 * The query is **title-only** (like Garuda, D-08-09): author/year are already
 * scored by `ReferenceScorer`, and adding them to a relevance query dilutes
 * retrieval. Normalization reuses {@see StringSimilarity::normalize()} because
 * Semantic Scholar documents that hyphenated query terms yield no matches.
 */
final class SemanticScholarQueryBuilder
{
    public const FIELDS = 'title,authors,year,venue,publicationVenue,journal,externalIds,url,publicationTypes';

    public function __construct(
        private readonly StringSimilarity $strings,
    ) {}

    /**
     * `/graph/v1/paper/search` query parameters.
     *
     * @return array<string, string|int>
     */
    public function searchParams(ReferenceQuery $query): array
    {
        $text = $this->strings->normalize($query->title ?? $query->bibliographic);
        $text = mb_substr($text, 0, max(4, (int) config('services.semantic_scholar.query_max_length', 300)));

        return [
            'query' => trim($text),
            'limit' => max(1, min(100, (int) config('services.semantic_scholar.limit', 5))),
            'fields' => self::FIELDS,
        ];
    }

    /**
     * `/graph/v1/paper/DOI:{doi}` path.
     *
     * Each `/`-delimited segment is encoded independently so the registrar
     * slash survives while `?`, `#`, spaces and non-ASCII suffix characters
     * remain URL-safe.
     */
    public function doiPath(string $normalizedDoi): string
    {
        return 'graph/v1/paper/DOI:'.implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $normalizedDoi),
        ));
    }
}
