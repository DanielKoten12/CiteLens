<?php

namespace App\Services\OpenAlex;

use App\Services\Metadata\ReferenceQuery;
use App\Services\Scoring\StringSimilarity;

/**
 * Builds OpenAlex Works API parameters and paths.
 *
 * `title.search` (OpenAlex maps it to `display_name.search`) is used instead of
 * the full-text `search=` parameter: both are priced as a search, but the
 * full-text one also matches works that merely *cite* the title. Year and
 * authors are deliberately **not** hard filters — a wrong extracted year must
 * not hide the correct record; they remain soft scoring signals.
 */
final class OpenAlexQueryBuilder
{
    public const SELECT = 'id,doi,title,publication_year,authorships,primary_location,type';

    public function __construct(
        private readonly StringSimilarity $strings,
    ) {}

    /**
     * `/works` query parameters.
     *
     * @return array<string, string|int>
     */
    public function searchParams(ReferenceQuery $query): array
    {
        $title = $this->strings->normalize($query->title ?? $query->bibliographic);
        $title = trim(mb_substr($title, 0, max(4, (int) config('services.openalex.query_max_length', 300))));

        return array_filter([
            'filter' => 'title.search:'.$title,
            'per-page' => max(1, min(100, (int) config('services.openalex.per_page', 5))),
            'select' => self::SELECT,
            'mailto' => trim((string) config('services.openalex.mailto')) ?: null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * `/works/doi:{doi}` path (a free single-entity lookup).
     *
     * Each `/`-delimited segment is encoded independently so the registrar
     * slash survives while `?`, `#`, spaces and non-ASCII suffix characters
     * remain URL-safe.
     */
    public function doiPath(string $normalizedDoi): string
    {
        return 'works/doi:'.implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $normalizedDoi),
        ));
    }

    /**
     * Query parameters for a single-entity DOI lookup.
     *
     * @return array<string, string>
     */
    public function doiParams(): array
    {
        $params = ['select' => self::SELECT];
        $mailto = trim((string) config('services.openalex.mailto'));

        if ($mailto !== '') {
            $params['mailto'] = $mailto;
        }

        return $params;
    }
}
