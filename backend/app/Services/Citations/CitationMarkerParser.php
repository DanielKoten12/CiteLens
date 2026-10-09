<?php

namespace App\Services\Citations;

use App\Services\Scoring\AuthorMatcher;

/**
 * Parses an in-text citation marker into a structured form.
 *
 * The parser is deliberately conservative (proposal scope: APA + IEEE only):
 * an unrecognized marker returns an empty {@see ParsedCitationMarker} and the
 * caller leaves the citation unpaired, because a false pairing is worse than a
 * `hallucination` verdict (`docs/plans/backend/05-citation-resolution-and-review-detail.md` §6).
 *
 * Supported shapes:
 * - IEEE: `[3]`, `[3], [5]`, `[3-5]`, `[3–5]`.
 * - APA parenthetical: `(Koten, 2023)`, `(Koten & Tani, 2023)`, `(LeCun et al., 2015)`.
 * - APA narrative: `Koten et al. (2023)`.
 *
 * A marker that references several publications (`[3], [5]`, `(A, 2020; B, 2021)`)
 * is reduced to the first/lowest ordinal (or the first author/year pair): the
 * canonical schema stores at most one reference per citation row (D-05-04).
 */
final class CitationMarkerParser
{
    /**
     * `[n]`, `[n, m]`, `[n-m]` / `[n–m]` ordinal groups.
     */
    private const string BRACKET_PATTERN = '/\[([^\]]*)\]/u';

    private const string RANGE_PATTERN = '/(\d+)\s*[-–—]\s*(\d+)/u';

    /**
     * A parenthetical `(authors, year)` pair, with authors kept as-is.
     */
    private const string APA_PARENTHETICAL_PATTERN = '/\(([^()]*?),\s*((?:19|20)\d{2}[a-z]?)\s*\)/iu';

    /**
     * A narrative `authors (year)` pair.
     */
    private const string APA_NARRATIVE_PATTERN = '/([^(),]+?)\s*\(((?:19|20)\d{2}[a-z]?)\)/u';

    /**
     * A bare `authors, year` marker (no parentheses).
     */
    private const string APA_BARE_PATTERN = '/^(.+?),\s*((?:19|20)\d{2}[a-z]?)\s*$/u';

    public function __construct(
        private readonly AuthorMatcher $authors,
    ) {}

    /**
     * Parse a citation marker, falling back to the raw citation text when the
     * marker is missing. Never throws; an unparseable input yields an empty
     * marker.
     */
    public function parse(?string $marker, ?string $citationText = null): ParsedCitationMarker
    {
        $raw = $marker !== null && trim($marker) !== '' ? $marker : $citationText;
        $raw = trim($raw ?? '');

        if ($raw === '') {
            return new ParsedCitationMarker;
        }

        $ordinals = $this->ordinals($raw);

        if ($ordinals !== []) {
            return new ParsedCitationMarker(ordinals: $ordinals);
        }

        return $this->apa($raw);
    }

    /**
     * Extract every bracketed ordinal, expanding ranges; returns an empty list
     * when the text carries no bracketed number.
     *
     * @return list<int>
     */
    private function ordinals(string $raw): array
    {
        $matched = preg_match_all(self::BRACKET_PATTERN, $raw, $groups, PREG_SET_ORDER);

        if ($matched === false || $matched === 0) {
            return [];
        }

        $ordinals = [];

        foreach ($groups as $group) {
            $body = $group[1] ?? '';

            $rangeCount = preg_match_all(self::RANGE_PATTERN, $body, $ranges, PREG_SET_ORDER);

            if ($rangeCount !== false && $rangeCount > 0) {
                foreach ($ranges as $range) {
                    $this->expandRange($ordinals, (int) $range[1], (int) $range[2]);
                }

                // Drop the consumed ranges before collecting remaining singles.
                $body = preg_replace(self::RANGE_PATTERN, ' ', $body) ?? $body;
            }

            if (preg_match_all('/\d+/', $body, $singles) !== false) {
                foreach ($singles[0] as $single) {
                    $value = (int) $single;

                    if ($value > 0) {
                        $ordinals[] = $value;
                    }
                }
            }
        }

        $ordinals = array_values(array_unique($ordinals));
        sort($ordinals);

        return $ordinals;
    }

    /**
     * Append an ascending range, bounded so a malformed marker cannot explode
     * into a huge ordinal list.
     *
     * @param  list<int>  $ordinals
     */
    private function expandRange(array &$ordinals, int $start, int $end): void
    {
        if ($start < 1 || $end < $start || ($end - $start) > 100) {
            return;
        }

        for ($value = $start; $value <= $end; $value++) {
            $ordinals[] = $value;
        }
    }

    private function apa(string $raw): ParsedCitationMarker
    {
        $raw = $this->firstAuthorYearSegment($raw);

        $authorsPart = null;
        $year = null;

        if (preg_match(self::APA_PARENTHETICAL_PATTERN, $raw, $match) === 1) {
            $authorsPart = $match[1];
            $year = (int) $match[2];
        } elseif (preg_match(self::APA_NARRATIVE_PATTERN, $raw, $match) === 1) {
            $authorsPart = $match[1];
            $year = (int) $match[2];
        } elseif (preg_match(self::APA_BARE_PATTERN, $raw, $match) === 1) {
            $authorsPart = $match[1];
            $year = (int) $match[2];
        }

        $surnames = $authorsPart === null ? [] : $this->authors->surnames($authorsPart);

        return new ParsedCitationMarker($surnames, $year);
    }

    /**
     * Reduce a multi-reference parenthetical `(A, 2020; B, 2021)` to its first
     * segment `(A, 2020)` (D-05-04). Narrative forms are left untouched: their
     * first parenthetical carries only the year.
     */
    private function firstAuthorYearSegment(string $raw): string
    {
        if (preg_match('/\(([^()]*)\)/u', $raw, $match) !== 1) {
            return $raw;
        }

        if (! str_contains($match[1], ';')) {
            return $raw;
        }

        $first = explode(';', $match[1], 2)[0];

        return '('.trim($first).')';
    }
}
