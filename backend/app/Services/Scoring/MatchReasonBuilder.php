<?php

namespace App\Services\Scoring;

use App\Enums\MetadataSource;

/**
 * The single source of user-facing verification reason strings (D-04-09).
 *
 * Finding `reason` strings are Indonesian and deterministic; candidate
 * `match_reason` strings cite the component evidence. Translating or tuning the
 * wording happens here and nowhere else.
 *
 * Wording is source-aware: verification is a chain (Crossref → Garuda →
 * Semantic Scholar → OpenAlex), so a reason must never claim "Crossref" when
 * another catalogue answered or could not answer (D-09-16).
 */
final class MatchReasonBuilder
{
    public const DOI_NOT_FOUND = 'DOI tidak ditemukan pada sumber metadata.';

    public const DOI_UNKNOWN = 'DOI belum dapat diverifikasi karena sumber metadata tidak tersedia. Coba lagi nanti.';

    public const MALFORMED_DOI = 'Format DOI tidak valid.';

    public const TRANSIENT = 'Validasi metadata gagal sementara. Coba lagi nanti.';

    public const DOI_MATCH = 'DOI cocok dengan metadata Crossref.';

    public const SUSPICIOUS = 'Judul pada metadata kandidat memiliki perbedaan.';

    public const NOT_FOUND = 'Tidak ada kandidat ditemukan.';

    public const LOCAL_VENUE = 'Sumber tidak terindeks di katalog eksternal; perlu pemeriksaan manual.';

    public const GARUDA_TRANSIENT = 'Validasi Garuda gagal sementara. Coba lagi nanti.';

    public const GARUDA_BUDGET = 'Batas permintaan Garuda tercapai; coba lagi nanti.';

    /**
     * Deterministic component evidence for one candidate.
     */
    public function forCandidate(ScoreBreakdown $breakdown, MetadataSource $source = MetadataSource::Crossref): string
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

        $evidence = $parts === []
            ? 'Tidak ada sinyal yang dapat dibandingkan.'
            : implode('; ', $parts).'.';

        return $source === MetadataSource::Crossref
            ? $evidence
            : $source->label().': '.$evidence;
    }

    /**
     * A DOI that resolved agrees with the reference metadata.
     */
    public function forDoiMatch(MetadataSource $source): string
    {
        return 'DOI cocok dengan metadata '.$source->label().'.';
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

    /**
     * Transient-failure reason for a source that could not answer.
     *
     * Garuda keeps its established literal (Phase 08 tests and reason wording);
     * the other supplemental sources use the generic source-aware shape.
     */
    public function transient(MetadataSource $source): string
    {
        return match ($source) {
            MetadataSource::Garuda => self::GARUDA_TRANSIENT,
            default => sprintf('Validasi %s gagal sementara. Coba lagi nanti.', $source->label()),
        };
    }

    /**
     * Budget-exhaustion reason for a fallback source that was skipped.
     */
    public function budget(MetadataSource $source): string
    {
        return match ($source) {
            MetadataSource::Garuda => self::GARUDA_BUDGET,
            default => sprintf('Batas permintaan %s tercapai; coba lagi nanti.', $source->label()),
        };
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
