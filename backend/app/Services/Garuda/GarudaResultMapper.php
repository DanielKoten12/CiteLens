<?php

namespace App\Services\Garuda;

use App\Enums\MetadataSource;
use App\Services\Metadata\WorkMetadata;
use App\Services\Scoring\StringSimilarity;

/**
 * Maps a parsed Garuda card to {@see WorkMetadata}.
 *
 * Garuda has no DOI field on most records, so `doi` is always `null`; the
 * record URL is the provenance. Titles are de-duplicated because Garuda often
 * stores `"Title: Title"` and the repeated half destroys title similarity.
 */
final class GarudaResultMapper
{
    private const DUPLICATE_THRESHOLD = 0.95;

    private const VOLUME_PATTERN = '/\s+vol(?:ume)?\.?\s+\d+.*$/iu';

    public function __construct(
        private readonly StringSimilarity $strings,
    ) {}

    /**
     * @param  array{id: string, title: string, authors: list<string>, journal: ?string, publisher: ?string, year: ?int}  $card
     */
    public function map(array $card, string $baseUrl): ?WorkMetadata
    {
        $title = trim($this->dedupeTitle($card['title']));

        if ($title === '') {
            return null;
        }

        return new WorkMetadata(
            doi: null,
            title: $title,
            authors: $card['authors'],
            containerTitle: $this->cleanJournal($card['journal']),
            publicationYear: $card['year'],
            url: rtrim($baseUrl, '/').'/documents/detail/'.$card['id'],
            type: 'journal-article',
            source: MetadataSource::Garuda,
        );
    }

    /**
     * @param  list<array{id: string, title: string, authors: list<string>, journal: ?string, publisher: ?string, year: ?int}>  $cards
     * @return list<WorkMetadata>
     */
    public function mapMany(array $cards, string $baseUrl): array
    {
        $works = [];

        foreach ($cards as $card) {
            $work = $this->map($card, $baseUrl);

            if ($work !== null) {
                $works[] = $work;
            }
        }

        return $works;
    }

    /**
     * Collapse the two duplicate shapes seen in Garuda records:
     * `"Title: Title"` (with or without further colons) and a repeated leading
     * phrase such as `"Literasi Digital Literasi Digital Sebagai ..."`.
     */
    private function dedupeTitle(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        if ($title === '') {
            return '';
        }

        $afterColon = $this->dedupeAfterColon($title);

        return $afterColon !== $title ? $afterColon : $this->dedupeRepeatedPrefix($title);
    }

    private function dedupeAfterColon(string $title): string
    {
        $offset = 0;

        while (($position = strpos($title, ': ', $offset)) !== false) {
            $left = trim(substr($title, 0, $position));
            $right = trim(substr($title, $position + 2));
            $similarity = $this->strings->jaroWinkler($left, $right);

            if ($left !== '' && $right !== '' && $similarity !== null && $similarity >= self::DUPLICATE_THRESHOLD) {
                return $left;
            }

            $offset = $position + 2;
        }

        return $title;
    }

    /**
     * Drop the first occurrence when the title starts with a repeated phrase of
     * at least two words. Requiring two words and a longer remainder keeps
     * legitimate repetitions ("Very Very Large Systems") untouched.
     */
    private function dedupeRepeatedPrefix(string $title): string
    {
        $words = explode(' ', $title);
        $count = count($words);

        for ($size = 2; $size <= intdiv($count, 2); $size++) {
            $prefix = implode(' ', array_slice($words, 0, $size));
            $rest = array_slice($words, $size);

            if (count($rest) <= $size) {
                continue;
            }

            $restPrefix = implode(' ', array_slice($rest, 0, $size));

            if (mb_strtolower($restPrefix) === mb_strtolower($prefix)) {
                return implode(' ', $rest);
            }
        }

        return $title;
    }

    /**
     * The card appends `Vol. 2 No. 1 (2023): JTPD` to the journal name; the
     * comparison field is the venue only.
     */
    private function cleanJournal(?string $journal): ?string
    {
        if ($journal === null || trim($journal) === '') {
            return null;
        }

        $journal = preg_replace(self::VOLUME_PATTERN, '', $journal) ?? $journal;
        $journal = trim($journal, " \t\n\r\0\x0B,;:-");

        return $journal === '' ? null : $journal;
    }
}
