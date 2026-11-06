<?php

namespace App\Services\SemanticScholar;

use App\Enums\MetadataSource;
use App\Services\Metadata\DoiNormalizer;
use App\Services\Metadata\WorkMetadata;

/**
 * Tolerant Semantic Scholar Graph API → {@see WorkMetadata} mapping.
 *
 * Shapes vary (missing titles, absent `externalIds`, venue in three possible
 * places), so every accessor degrades to `null`/`[]` instead of throwing. A
 * record with neither a DOI nor a title is unusable and is skipped.
 */
final class SemanticScholarResultMapper
{
    public function __construct(
        private readonly DoiNormalizer $normalizer,
    ) {}

    /**
     * Body of `GET /graph/v1/paper/search` (`data[]`).
     *
     * @param  array<string, mixed>  $payload
     * @return list<WorkMetadata>
     */
    public function mapSearch(array $payload): array
    {
        $papers = $payload['data'] ?? null;

        if (! is_array($papers)) {
            return [];
        }

        $works = [];

        foreach ($papers as $paper) {
            if (! is_array($paper)) {
                continue;
            }

            $work = $this->mapPaper($paper);

            if ($work !== null) {
                $works[] = $work;
            }
        }

        return $works;
    }

    /**
     * Body of `GET /graph/v1/paper/DOI:{doi}` (a single paper object).
     *
     * @param  array<string, mixed>  $paper
     */
    public function mapPaper(array $paper): ?WorkMetadata
    {
        $doi = $this->normalizer->normalize($this->nestedString($paper['externalIds'] ?? null, 'DOI'));
        $title = $this->stringOrNull($paper['title'] ?? null);

        if ($doi === null && $title === null) {
            return null;
        }

        $paperId = $this->stringOrNull($paper['paperId'] ?? null);

        return new WorkMetadata(
            doi: $doi,
            title: $title,
            authors: $this->authors($paper['authors'] ?? null),
            containerTitle: $this->containerTitle($paper),
            publicationYear: $this->year($paper['year'] ?? null),
            url: $this->stringOrNull($paper['url'] ?? null)
                ?? ($paperId === null ? null : 'https://www.semanticscholar.org/paper/'.$paperId),
            type: $this->type($paper['publicationTypes'] ?? null),
            source: MetadataSource::SemanticScholar,
        );
    }

    /**
     * `journal.name` → `publicationVenue.name` → `venue`.
     *
     * @param  array<string, mixed>  $paper
     */
    private function containerTitle(array $paper): ?string
    {
        return $this->nestedString($paper['journal'] ?? null, 'name')
            ?? $this->nestedString($paper['publicationVenue'] ?? null, 'name')
            ?? $this->stringOrNull($paper['venue'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function authors(mixed $authors): array
    {
        if (! is_array($authors)) {
            return [];
        }

        $names = [];

        foreach ($authors as $author) {
            $name = is_array($author) ? $this->stringOrNull($author['name'] ?? null) : null;

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function type(mixed $publicationTypes): ?string
    {
        if (! is_array($publicationTypes)) {
            return null;
        }

        foreach ($publicationTypes as $type) {
            if (is_string($type) && trim($type) !== '') {
                return mb_strtolower(trim($type));
            }
        }

        return null;
    }

    private function year(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $year = (int) $value;

        return $year >= 1000 && $year <= 2100 ? $year : null;
    }

    private function nestedString(mixed $value, string $key): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        return $this->stringOrNull($value[$key] ?? null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }
}
