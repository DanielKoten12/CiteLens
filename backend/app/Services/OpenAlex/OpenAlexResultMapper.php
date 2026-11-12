<?php

namespace App\Services\OpenAlex;

use App\Enums\MetadataSource;
use App\Services\Metadata\DoiNormalizer;
use App\Services\Metadata\WorkMetadata;

/**
 * Tolerant OpenAlex Works API → {@see WorkMetadata} mapping.
 *
 * Shapes vary (plain `doi` URL, `title` vs `display_name`, missing source or
 * landing page), so every accessor degrades to `null`/`[]` instead of throwing.
 * A work with neither a DOI nor a title is unusable and is skipped.
 */
final class OpenAlexResultMapper
{
    public function __construct(
        private readonly DoiNormalizer $normalizer,
    ) {}

    /**
     * Body of `GET /works` (`results[]`).
     *
     * @param  array<string, mixed>  $payload
     * @return list<WorkMetadata>
     */
    public function mapSearch(array $payload): array
    {
        $results = $payload['results'] ?? null;

        if (! is_array($results)) {
            return [];
        }

        $works = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $work = $this->mapWork($result);

            if ($work !== null) {
                $works[] = $work;
            }
        }

        return $works;
    }

    /**
     * Body of `GET /works/doi:{doi}` (a single work object).
     *
     * @param  array<string, mixed>  $result
     */
    public function mapWork(array $result): ?WorkMetadata
    {
        $doi = $this->normalizer->normalize($this->stringOrNull($result['doi'] ?? null));
        $title = $this->stringOrNull($result['title'] ?? null)
            ?? $this->stringOrNull($result['display_name'] ?? null);

        if ($doi === null && $title === null) {
            return null;
        }

        return new WorkMetadata(
            doi: $doi,
            title: $title,
            authors: $this->authors($result['authorships'] ?? null),
            containerTitle: $this->containerTitle($result['primary_location'] ?? null),
            publicationYear: $this->year($result['publication_year'] ?? null),
            url: $this->url($doi, $result),
            type: $this->stringOrNull($result['type'] ?? null),
            source: MetadataSource::OpenAlex,
        );
    }

    /**
     * @return list<string>
     */
    private function authors(mixed $authorships): array
    {
        if (! is_array($authorships)) {
            return [];
        }

        $names = [];

        foreach ($authorships as $authorship) {
            if (! is_array($authorship)) {
                continue;
            }

            $author = $authorship['author'] ?? null;
            $name = is_array($author)
                ? $this->stringOrNull($author['display_name'] ?? null)
                : null;

            $name ??= $this->stringOrNull($authorship['raw_author_name'] ?? null);

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function containerTitle(mixed $primaryLocation): ?string
    {
        if (! is_array($primaryLocation)) {
            return null;
        }

        $source = $primaryLocation['source'] ?? null;

        return is_array($source)
            ? $this->stringOrNull($source['display_name'] ?? null)
            : null;
    }

    /**
     * Prefer the canonical DOI URL, then the landing page, then the record id.
     *
     * @param  array<string, mixed>  $result
     */
    private function url(?string $doi, array $result): ?string
    {
        if ($doi !== null) {
            return 'https://doi.org/'.$doi;
        }

        $primaryLocation = $result['primary_location'] ?? null;

        if (is_array($primaryLocation)) {
            $landingPage = $this->stringOrNull($primaryLocation['landing_page_url'] ?? null);

            if ($landingPage !== null) {
                return $landingPage;
            }
        }

        return $this->stringOrNull($result['id'] ?? null);
    }

    private function year(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $year = (int) $value;

        return $year >= 1000 && $year <= 2100 ? $year : null;
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
