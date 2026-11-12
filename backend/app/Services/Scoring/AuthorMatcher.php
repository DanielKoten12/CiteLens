<?php

namespace App\Services\Scoring;

/**
 * Order-insensitive author similarity.
 *
 * The reference side is one extracted string (usually APA `Surname, Initial.`);
 * the candidate side is the Crossref author list, one `Given Family` string per
 * author. Author names are parsed conservatively to surnames, matched greedily
 * best-pair with Jaro-Winkler, and averaged over the smaller set so an
 * `et al.`-truncated reference is not punished for missing authors.
 */
final class AuthorMatcher
{
    public function __construct(
        private readonly StringSimilarity $strings,
    ) {}

    /**
     * @param  list<string>  $candidateAuthors
     */
    public function similarity(?string $referenceAuthors, array $candidateAuthors): ?float
    {
        $reference = $this->surnames($referenceAuthors);

        $candidate = [];

        foreach ($candidateAuthors as $author) {
            foreach ($this->surnames($author) as $surname) {
                $candidate[] = $surname;
            }
        }

        if ($reference === [] || $candidate === []) {
            return null;
        }

        $used = [];
        $scores = [];

        foreach ($reference as $surname) {
            $best = null;
            $bestIndex = null;

            foreach ($candidate as $index => $candidateSurname) {
                if (isset($used[$index])) {
                    continue;
                }

                $score = $this->strings->jaroWinkler($surname, $candidateSurname) ?? 0.0;

                if ($best === null || $score > $best) {
                    $best = $score;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                continue;
            }

            $used[$bestIndex] = true;
            $scores[] = $best;
        }

        if ($scores === []) {
            return null;
        }

        $score = array_sum($scores) / count($scores);

        $smallest = min(count($reference), count($candidate));
        $largest = max(count($reference), count($candidate));

        if ($largest > 0 && ($smallest / $largest) < 0.5) {
            $score *= 0.9;
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * Parse a raw author string into surnames.
     *
     * Kept for reference/candidate scoring; delegates to {@see self::names()} so
     * there is exactly one author parser. Output is unchanged from the original
     * implementation (regression-tested).
     *
     * @return list<string>
     */
    public function surnames(?string $authors): array
    {
        return array_values(array_map(
            static fn (AuthorName $name): string => $name->surname,
            $this->names($authors),
        ));
    }

    /**
     * Parse a raw author string into structured names (surname + initials).
     *
     * Handles `LeCun, Y., Bengio, Y., & Hinton, G.` (surname-first with initials),
     * `Yann LeCun` (given-first) and `LeCun Y.` (surname + trailing initials).
     * A comma inside a single author (`LeCun, Y.`) is distinguished from an
     * author separator by pairing comma tokens (surname, given, surname, given, …).
     *
     * @return list<AuthorName>
     */
    public function names(?string $authors): array
    {
        if ($authors === null) {
            return [];
        }

        $authors = trim($authors);

        if ($authors === '') {
            return [];
        }

        // Drop truncation markers so `Koten et al.` is not parsed as a surname.
        $authors = preg_replace('/\s+(?:et\s+al\.?|dkk\.?)\s*$/iu', '', $authors) ?? $authors;

        if (trim($authors) === '') {
            return [];
        }

        $names = [];
        $chunks = preg_split('/\s*(?:&|;|\band\b|\bdkk\.?)\s*/iu', $authors) ?: [];

        foreach ($chunks as $chunk) {
            $tokens = array_values(array_filter(
                array_map(static fn (string $token): string => trim($token, " \t\n\r\0\x0B."), preg_split('/\s*,\s*/u', trim($chunk)) ?: []),
                static fn (string $token): bool => $token !== '',
            ));

            if ($tokens === []) {
                continue;
            }

            if (count($tokens) === 1) {
                $name = $this->nameFromSingleToken($tokens[0]);

                if ($name !== null) {
                    $names[] = $name;
                }

                continue;
            }

            for ($i = 0; $i < count($tokens); $i += 2) {
                $surname = $this->surnameFromSingleToken($tokens[$i]);

                if ($surname === '') {
                    continue;
                }

                $given = $tokens[$i + 1] ?? null;
                $names[] = new AuthorName($surname, $given === null ? null : $this->initialsFrom($given));
            }
        }

        return $names;
    }

    /**
     * A chunk with no comma is either `Family`, `Given Family` or
     * `Family Initials`; the last word is the surname unless it looks like an
     * initial (`LeCun Y`). Initials are captured from the given-name side.
     */
    private function nameFromSingleToken(string $token): ?AuthorName
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', $token) ?: [],
            static fn (string $word): bool => $word !== '',
        ));

        if ($words === []) {
            return null;
        }

        if (count($words) === 1) {
            return new AuthorName($words[0]);
        }

        $last = end($words);

        if (! is_string($last) || $last === '') {
            return new AuthorName($token);
        }

        if ($this->looksLikeInitial($last)) {
            return new AuthorName($words[0], $this->initialsFromWords(array_slice($words, 1)));
        }

        return new AuthorName($last, $this->initialsFromWords(array_slice($words, 0, -1)));
    }

    /**
     * A chunk with no comma is either `Family` or `Given Family`; the last word
     * is the surname unless it looks like an initial (`LeCun Y`).
     */
    private function surnameFromSingleToken(string $token): string
    {
        $words = preg_split('/\s+/u', $token) ?: [];

        if (count($words) <= 1) {
            return $token;
        }

        $last = end($words);

        if (! is_string($last) || $last === '') {
            return $token;
        }

        if ($this->looksLikeInitial($last)) {
            return $words[0];
        }

        return $last;
    }

    /**
     * First letter of every given-name token, uppercased (`D. B.` → `DB`).
     */
    private function initialsFrom(string $givenNames): ?string
    {
        $tokens = preg_split('/[\s.]+/u', $givenNames) ?: [];

        return $this->initialsFromWords($tokens);
    }

    /**
     * @param  list<string>  $words
     */
    private function initialsFromWords(array $words): ?string
    {
        $initials = '';

        foreach ($words as $word) {
            if (preg_match('/^\p{L}/u', trim($word), $match) === 1) {
                $initials .= mb_strtoupper($match[0]);
            }
        }

        return $initials === '' ? null : $initials;
    }

    private function looksLikeInitial(string $word): bool
    {
        return preg_match('/^\p{Lu}\.?$/u', $word) === 1;
    }
}
