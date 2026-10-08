<?php

namespace App\Services\Crossref;

/**
 * Tolerant Crossref JSON → {@see CrossrefWorkData} mapping.
 *
 * Crossref metadata shapes vary (missing titles, absent `issued`, single vs.
 * list fields), so every accessor degrades to `null`/`[]` instead of throwing.
 * A work with neither a DOI nor a title is unusable and is skipped.
 */
final class CrossrefResultMapper
{
    public function __construct(
        private readonly DoiNormalizer $normalizer,
    ) {}

    /**
     * Body of `GET /works/{doi}` (the wrapper containing `message`).
     *
     * @param  array<string, mixed>  $payload
     */
    public function mapSingle(array $payload): ?CrossrefWorkData
    {
        $message = $payload['message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        return $this->mapWork($message);
    }

    /**
     * Body of `GET /works` (`message.items[]`).
     *
     * @param  array<string, mixed>  $payload
     * @return list<CrossrefWorkData>
     */
    public function mapList(array $payload): array
    {
        $items = $payload['message']['items'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $works = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $work = $this->mapWork($item);

            if ($work !== null) {
                $works[] = $work;
            }
        }

        return $works;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function mapWork(array $message): ?CrossrefWorkData
    {
        $doi = $this->normalizer->normalize($this->stringOrNull($message['DOI'] ?? null));
        $title = $this->firstString($message['title'] ?? null);

        if ($doi === null && ($title === null || trim($title) === '')) {
            return null;
        }

        return new CrossrefWorkData(
            doi: $doi,
            title: $title,
            authors: $this->authors($message['author'] ?? null),
            containerTitle: $this->firstString($message['container-title'] ?? null),
            publicationYear: $this->issuedYear($message['issued'] ?? null),
            url: $this->stringOrNull($message['URL'] ?? null),
            type: $this->stringOrNull($message['type'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private function authors(mixed $author): array
    {
        if (! is_array($author)) {
            return [];
        }

        $names = [];

        foreach ($author as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $given = $this->stringOrNull($entry['given'] ?? null);
            $family = $this->stringOrNull($entry['family'] ?? null);
            $literal = $this->stringOrNull($entry['name'] ?? null);

            $name = trim(implode(' ', array_filter(
                [$given, $family],
                static fn (?string $value): bool => $value !== null && trim($value) !== '',
            )));

            if ($name === '' && $literal !== null) {
                $name = $literal;
            }

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function issuedYear(mixed $issued): ?int
    {
        if (! is_array($issued)) {
            return null;
        }

        $dateParts = $issued['date-parts'] ?? null;

        if (is_array($dateParts) && isset($dateParts[0]) && is_array($dateParts[0]) && isset($dateParts[0][0])) {
            $year = $dateParts[0][0];

            if (is_numeric($year) && ($year = (int) $year) >= 1000 && $year <= 2100) {
                return $year;
            }
        }

        $timestamp = $issued['timestamp'] ?? null;

        if (is_numeric($timestamp)) {
            $year = (int) date('Y', (int) ((int) $timestamp / 1000));

            if ($year >= 1000 && $year <= 2100) {
                return $year;
            }
        }

        return null;
    }

    private function firstString(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                return trim($entry);
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
