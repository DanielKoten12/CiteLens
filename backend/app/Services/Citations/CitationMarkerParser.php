<?php

namespace App\Services\Citations;

use App\Services\Scoring\AuthorMatcher;

/**
 * Parses an in-text citation marker into structured author/year pairs and IEEE
 * ordinals.
 *
 * The parser is deliberately conservative (proposal scope: APA + IEEE only):
 * an unrecognized marker returns an empty {@see ParsedCitationMarker} and the
 * caller leaves the citation unpairable, because a false pairing is worse than
 * an explicit unresolved/hallucination verdict.
 *
 * Supported shapes:
 * - IEEE: `[3]`, `[3], [5]`, `[3-5]`, `[3–5]` (every ordinal is retained).
 * - APA parenthetical: `(Koten, 2023)`, `(Koten & Tani, 2023)`,
 *   `(LeCun et al., 2015)`, `(A, 2020; B, 2021)` (every pair is retained).
 * - APA narrative: `Koten et al. (2023)`, `Koten (2023); Tani (2021)`.
 * - Bare: `LeCun et al., 2015`.
 *
 * Author names are parsed with {@see AuthorMatcher::names()} so initials are
 * available to the resolver for same-surname disambiguation.
 */
final class CitationMarkerParser
{
    /**
     * `[n]`, `[n, m]`, `[n-m]` / `[n–m]` ordinal groups.
     */
    private const string BRACKET_PATTERN = '/\[([^\]]*)\]/u';

    private const string RANGE_PATTERN = '/(\d+)\s*[-–—]\s*(\d+)/u';

    /**
     * A parenthetical group, e.g. `(A, 2020; B, 2021)`.
     */
    private const string PARENTHETICAL_PATTERN = '/\(([^()]*)\)/u';

    /**
     * A narrative pair: `Authors (year)`.
     */
    private const string NARRATIVE_PATTERN = '/([^();]+?)\s*\(((?:19|20)\d{2}[a-z]?)\)/u';

    /**
     * A bare pair: `Authors, year` at the start of a `;`-segment.
     */
    private const string BARE_PATTERN = '/^(.+?),\s*((?:19|20)\d{2}[a-z]?)\s*$/u';

    /**
     * `authors, year` inside a parenthetical segment.
     */
    private const string SEGMENT_PATTERN = '/^(.+?),\s*((?:19|20)\d{2}[a-z]?)\s*$/u';

    /**
     * A segment that is only a year (the parenthetical half of a narrative pair).
     */
    private const string YEAR_ONLY_PATTERN = '/^\d{4}[a-z]?$/u';

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

        return new ParsedCitationMarker($this->apaPairs($raw));
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

    /**
     * @return list<ParsedAuthorYear>
     */
    private function apaPairs(string $raw): array
    {
        $pairs = [];

        // 1. Parenthetical groups, split on `;` into one pair per segment.
        if (preg_match_all(self::PARENTHETICAL_PATTERN, $raw, $groups) !== false) {
            foreach ($groups[1] as $content) {
                foreach (preg_split('/;/u', $content) ?: [] as $segment) {
                    $pair = $this->pairFromSegment(trim($segment));

                    if ($pair !== null) {
                        $pairs[] = $pair;
                    }
                }
            }
        }

        // 2. Narrative forms: `Authors (year)`.
        if (preg_match_all(self::NARRATIVE_PATTERN, $raw, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $pair = $this->pairFromAuthors($match[1], (int) $match[2]);

                if ($pair !== null) {
                    $pairs[] = $pair;
                }
            }
        }

        // 3. Bare `authors, year` segments when nothing else matched.
        if ($pairs === []) {
            foreach (preg_split('/;/u', $raw) ?: [] as $segment) {
                if (preg_match(self::BARE_PATTERN, trim($segment), $match) !== 1) {
                    continue;
                }

                $pair = $this->pairFromAuthors($match[1], (int) $match[2]);

                if ($pair !== null) {
                    $pairs[] = $pair;
                }
            }
        }

        return $this->uniquePairs($pairs);
    }

    private function pairFromSegment(string $segment): ?ParsedAuthorYear
    {
        if ($segment === '' || preg_match(self::YEAR_ONLY_PATTERN, $segment) === 1) {
            return null;
        }

        if (preg_match(self::SEGMENT_PATTERN, $segment, $match) === 1) {
            return $this->pairFromAuthors($match[1], (int) $match[2]);
        }

        return $this->pairFromAuthors($segment, null);
    }

    private function pairFromAuthors(string $authorsPart, ?int $year): ?ParsedAuthorYear
    {
        $names = $this->authors->names($authorsPart);

        if ($names === []) {
            return null;
        }

        return new ParsedAuthorYear($names, $year);
    }

    /**
     * Keep the first occurrence of each distinct `(surnames, year)` pair.
     *
     * @param  list<ParsedAuthorYear>  $pairs
     * @return list<ParsedAuthorYear>
     */
    private function uniquePairs(array $pairs): array
    {
        $seen = [];
        $unique = [];

        foreach ($pairs as $pair) {
            $key = $pair->key();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $pair;
        }

        return $unique;
    }
}
