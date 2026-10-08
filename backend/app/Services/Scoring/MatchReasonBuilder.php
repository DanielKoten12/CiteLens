<?php

namespace App\Services\Scoring;

/**
 * The single source of user-facing verification reason strings (D-04-09).
 *
 * Finding `reason` strings are Indonesian and deterministic; candidate
 * `match_reason` strings cite the component evidence. Translating or tuning the
 * wording happens here and nowhere else.
 */
final class MatchReasonBuilder
{
    public const DOI_NOT_FOUND = 'DOI tidak ditemukan di Crossref.';

    public const MALFORMED_DOI = 'Format DOI tidak valid.';

    public const TRANSIENT = 'Validasi Crossref gagal sementara. Coba lagi nanti.';

    public const DOI_MATCH = 'DOI cocok dengan metadata Crossref.';

    public const SUSPICIOUS = 'Judul pada metadata Crossref memiliki perbedaan.';

    public const NOT_FOUND = 'Tidak ada kandidat ditemukan di Crossref.';

    public const LOCAL_VENUE = 'Sumber tidak terindeks Crossref; perlu pemeriksaan manual.';

    /**
     * Deterministic component evidence for one candidate.
     */
    public function forCandidate(ScoreBreakdown $breakdown): string
    {
        $parts = [];

        if ($breakdown->doiMatch) {
            $parts[] = 'DOI cocok';
        }

        $title = $breakdown->signal('title');

        if ($title !== null) {
            $parts[] = sprintf('kemiripan judul %.2f', $title);
        }

        $authors = $breakdown->signal('authors');

        if ($authors !== null) {
            $parts[] = sprintf('kemiripan penulis %.2f', $authors);
        }

        $journal = $breakdown->signal('journal');

        if ($journal !== null) {
            $parts[] = sprintf('kemiripan jurnal %.2f', $journal);
        }

        $year = $breakdown->signal('year');

        if ($year !== null) {
            $parts[] = $year >= 1.0 ? 'tahun cocok' : 'tahun berbeda';
        }

        if ($breakdown->semanticDegraded) {
            $parts[] = 'kemiripan semantik tidak tersedia';
        }

        return $parts === []
            ? 'Tidak ada sinyal yang dapat dibandingkan.'
            : implode('; ', $parts).'.';
    }

    /**
     * @param  list<string>  $conflicts
     */
    public function forDoiConflict(array $conflicts): string
    {
        $fields = $conflicts === []
            ? 'metadata'
            : implode(', ', array_map($this->fieldLabel(...), $conflicts));

        return "DOI menunjuk ke publikasi yang berbeda. Perbedaan: {$fields}.";
    }

    public function forNoDoiValid(ScoredCandidate $candidate): string
    {
        return $candidate->work->doi === null
            ? 'Kandidat terbaik memiliki kemiripan tinggi.'
            : "Kandidat dengan DOI {$candidate->work->doi} memiliki kemiripan tinggi.";
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'title' => 'judul',
            'authors' => 'penulis',
            'journal' => 'jurnal',
            'year' => 'tahun',
            default => $field,
        };
    }
}
