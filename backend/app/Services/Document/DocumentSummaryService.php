<?php

namespace App\Services\Document;

use App\Data\ResearchedDocument\DocumentAnalysisSummaryData;
use App\Enums\CitationStatus;
use App\Enums\DocumentStatus;
use App\Enums\ReferenceFindingStatus;
use App\Models\ResearchedDocument;
use App\Services\Citations\CitationStatusResolver;
use Illuminate\Support\Facades\DB;

/**
 * Computes the derived document summary counts (`docs/API_SPEC.md` §4).
 *
 * Counts are derived, never stored. A single document costs three grouped
 * aggregate queries; a page of documents costs the same three queries (one
 * `WHERE ... IN (...)` each), so the list endpoint has no N+1.
 */
final class DocumentSummaryService
{
    public function __construct(
        private readonly CitationStatusResolver $citationStatusResolver,
    ) {}

    /**
     * Summary for one document, or `null` when the analysis has not completed (OQ-10).
     */
    public function forDocument(ResearchedDocument $document): ?DocumentAnalysisSummaryData
    {
        if ($document->status !== DocumentStatus::Completed) {
            return null;
        }

        return $this->summariesForIds([$document->getKey()])[$document->getKey()] ?? null;
    }

    /**
     * Derived counts for one document regardless of status.
     *
     * Used by the pipeline's finalization step for observability; the API-facing
     * `forDocument()` keeps the `null`-until-completed contract (OQ-10).
     */
    public function countsFor(ResearchedDocument $document): DocumentAnalysisSummaryData
    {
        return $this->summariesForIds([$document->getKey()])[$document->getKey()]
            ?? DocumentAnalysisSummaryData::forCounts(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
    }

    /**
     * Batched summaries, keyed by document id. Only completed documents are present.
     *
     * @param  iterable<ResearchedDocument>  $documents
     * @return array<string, DocumentAnalysisSummaryData>
     */
    public function forDocuments(iterable $documents): array
    {
        $ids = [];

        foreach ($documents as $document) {
            if ($document->status === DocumentStatus::Completed) {
                $ids[] = $document->getKey();
            }
        }

        return $this->summariesForIds($ids);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, DocumentAnalysisSummaryData>
     */
    private function summariesForIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $referenceCounts = $this->referenceCounts($ids);
        $findingCounts = $this->findingCounts($ids);
        $citationCounts = $this->citationCounts($ids);

        $summaries = [];

        foreach ($ids as $id) {
            $findings = $findingCounts[$id] ?? [];
            $citations = $citationCounts[$id] ?? [];

            $summaries[$id] = DocumentAnalysisSummaryData::forCounts(
                totalReferences: (int) ($referenceCounts[$id] ?? 0),
                valid: (int) ($findings[ReferenceFindingStatus::Valid->value] ?? 0),
                suspicious: (int) ($findings[ReferenceFindingStatus::Suspicious->value] ?? 0),
                invalid: (int) ($findings[ReferenceFindingStatus::Invalid->value] ?? 0),
                notFound: (int) ($findings[ReferenceFindingStatus::NotFound->value] ?? 0),
                totalCitations: (int) ($citations['total'] ?? 0),
                validCitations: (int) ($citations[CitationStatus::Valid->value] ?? 0),
                unreliableCitations: (int) ($citations[CitationStatus::Unreliable->value] ?? 0),
                pendingCitations: (int) ($citations[CitationStatus::Pending->value] ?? 0),
                unresolvedCitations: (int) ($citations[CitationStatus::Unresolved->value] ?? 0),
                hallucinationCitations: (int) ($citations[CitationStatus::Hallucination->value] ?? 0),
            );
        }

        return $summaries;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function referenceCounts(array $ids): array
    {
        return DB::table('researched_document_references')
            ->whereIn('researched_document_id', $ids)
            ->groupBy('researched_document_id')
            ->select('researched_document_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->pluck('aggregate', 'researched_document_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, int>>
     */
    private function findingCounts(array $ids): array
    {
        $rows = DB::table('reference_findings')
            ->whereIn('researched_document_id', $ids)
            ->groupBy('researched_document_id', 'status')
            ->select('researched_document_id', 'status')
            ->selectRaw('COUNT(*) as aggregate')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->researched_document_id][$row->status] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, int>>
     */
    private function citationCounts(array $ids): array
    {
        $derived = $this->citationStatusResolver->sqlExpression(
            'c.resolution_state',
            'f.status',
        );

        $rows = DB::table('researched_document_citations as c')
            ->leftJoin('researched_document_references as r', 'r.id', '=', 'c.researched_document_reference_id')
            ->leftJoin('reference_findings as f', 'f.researched_document_reference_id', '=', 'r.id')
            ->whereIn('c.researched_document_id', $ids)
            ->groupBy('c.researched_document_id')
            ->select('c.researched_document_id')
            ->selectRaw($this->citationCountSelect($derived))
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->researched_document_id] = [
                'total' => (int) $row->total,
                CitationStatus::Valid->value => (int) $row->{CitationStatus::Valid->value},
                CitationStatus::Unreliable->value => (int) $row->{CitationStatus::Unreliable->value},
                CitationStatus::Pending->value => (int) $row->{CitationStatus::Pending->value},
                CitationStatus::Unresolved->value => (int) $row->{CitationStatus::Unresolved->value},
                CitationStatus::Hallucination->value => (int) $row->{CitationStatus::Hallucination->value},
            ];
        }

        return $counts;
    }

    /**
     * Build the count-per-derived-status SELECT fragment from the enum values
     * (trusted constants; the column names are validated by the resolver).
     */
    private function citationCountSelect(string $derived): string
    {
        $columns = ['COUNT(*) as total'];

        foreach (CitationStatus::cases() as $status) {
            $value = $status->value;
            $columns[] = "SUM(CASE WHEN ({$derived}) = '{$value}' THEN 1 ELSE 0 END) as {$value}";
        }

        return implode(', ', $columns);
    }
}
