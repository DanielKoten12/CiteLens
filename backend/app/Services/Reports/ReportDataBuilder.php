<?php

namespace App\Services\Reports;

use App\Enums\CitationStatus;
use App\Enums\ReferenceFindingStatus;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use App\Services\Citation\CitationQueryService;
use App\Services\Citations\CitationStatusResolver;
use App\Services\Document\DocumentSummaryService;
use App\Services\Reference\ReferenceQueryService;
use Illuminate\Support\Str;

/**
 * Builds the report view model from the existing read models.
 *
 * No derivation is re-implemented here: counts come from
 * {@see DocumentSummaryService}, citation status from
 * {@see CitationStatusResolver}, and citation-issue messages from
 * {@see CitationStatus::message()} (D-06-05). Extracted text is
 * truncated to `reports.text_preview_length` (D-06-12).
 */
final class ReportDataBuilder
{
    public function __construct(
        private readonly DocumentSummaryService $summaries,
        private readonly ReferenceQueryService $references,
        private readonly CitationQueryService $citations,
        private readonly CitationStatusResolver $resolver,
    ) {}

    public function build(ResearchedDocument $document): ReportPayload
    {
        $limit = (int) config('reports.text_preview_length');

        $references = $this->references->all($document)
            ->map(fn (ResearchedDocumentReference $reference): ReportReferenceRow => new ReportReferenceRow(
                rawText: $this->preview($reference->raw_text, $limit),
                doi: $reference->doi,
                title: $this->preview($reference->title, $limit),
                authors: $this->preview($reference->authors, $limit),
                publicationName: $this->preview($reference->publication_name, $limit),
                publicationYear: $reference->publication_year,
                status: $reference->finding?->status ?? ReferenceFindingStatus::Pending,
                confidence: $reference->finding?->confidence,
                reason: $reference->finding?->reason,
            ))
            ->values()
            ->all();

        $citationIssues = $this->citations->issues($document)
            ->map(function (ResearchedDocumentCitation $citation) use ($limit): ReportCitationRow {
                $status = $this->resolver->resolve(
                    $citation->resolution_state,
                    $citation->reference?->finding?->status,
                );

                return new ReportCitationRow(
                    citationText: $this->preview($citation->citation_text, $limit),
                    status: $status,
                    referenceLabel: $this->preview(
                        $citation->reference?->title ?? $citation->reference?->raw_text,
                        $limit,
                    ),
                    message: (string) $status->message(),
                );
            })
            ->values()
            ->all();

        return new ReportPayload(
            documentName: $document->name,
            documentCreatedAt: $document->created_at->toImmutable(),
            analysisCompletedAt: $document->analysis_completed_at?->toImmutable(),
            summary: $this->summaries->countsFor($document),
            references: $references,
            citationIssues: $citationIssues,
            generatedAt: now()->toImmutable(),
        );
    }

    private function preview(?string $text, int $limit): ?string
    {
        return $text === null || $text === '' ? null : Str::limit($text, max(1, $limit));
    }
}
