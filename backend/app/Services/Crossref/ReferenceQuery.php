<?php

namespace App\Services\Crossref;

use App\Models\ResearchedDocumentReference;

/**
 * Bibliographic search input for Crossref `/works`.
 *
 * Built from one bibliography entry; the author is reduced to a single surname
 * because `query.author` is a relevance hint, not a full author index.
 */
final class ReferenceQuery
{
    public function __construct(
        public readonly string $bibliographic,
        public readonly ?string $author = null,
        public readonly ?int $year = null,
    ) {}

    public static function fromReference(ResearchedDocumentReference $reference): self
    {
        $author = self::firstAuthorSurname($reference->authors);

        $parts = array_values(array_filter([
            $reference->title,
            $author,
            $reference->publication_year !== null ? (string) $reference->publication_year : null,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== ''));

        return new self(
            bibliographic: implode(' ', $parts),
            author: $author,
            year: $reference->publication_year,
        );
    }

    /**
     * Best-effort first surname from an extracted author string.
     *
     * Handles `LeCun, Y., Bengio, Y.` (surname before the first comma) and
     * `Yann LeCun` (last word). Returns `null` when nothing usable is present;
     * the query builder then omits `query.author`.
     */
    private static function firstAuthorSurname(?string $authors): ?string
    {
        if ($authors === null) {
            return null;
        }

        $authors = trim($authors);

        if ($authors === '') {
            return null;
        }

        $first = preg_split('/\s*(?:;|&|,|\band\b)\s*/iu', $authors)[0] ?? null;

        if ($first === null) {
            return null;
        }

        $first = trim($first, " \t\n\r\0\x0B.");
        $first = preg_replace('/\s+(?:et\s+al\.?|dkk\.?)$/iu', '', $first) ?? $first;

        if ($first === '') {
            return null;
        }

        if (str_contains($first, ' ')) {
            $words = preg_split('/\s+/u', $first) ?: [];
            $surname = end($words);

            return is_string($surname) && $surname !== '' ? $surname : null;
        }

        return $first;
    }
}
