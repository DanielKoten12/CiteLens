<?php

namespace App\Services\Analysis\Steps;

use App\Enums\AnalysisStep;
use App\Models\ResearchedDocument;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\Document\DocumentSummaryService;
use Illuminate\Support\Facades\Log;

/**
 * Final bookkeeping step of the canonical sequence (`generating_report`).
 *
 * Reports are generated on demand (OQ-08), so this step does **not** render a
 * PDF. It records a structured completion entry (reference/citation counts) for
 * observability; the runner then transitions the document to `completed`.
 */
final class FinalizeAnalysisStep implements PipelineStep
{
    public function __construct(
        private readonly DocumentSummaryService $summaryService,
    ) {}

    public function step(): AnalysisStep
    {
        return AnalysisStep::GeneratingReport;
    }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        $counts = $this->summaryService->countsFor($document);

        Log::info('Document analysis finalized.', [
            'document_id' => $document->getKey(),
            'total_references' => $counts->totalReferences,
            'total_citations' => $counts->totalCitations,
            'pending_citations' => $counts->pendingCitations,
            'hallucination_citations' => $counts->hallucinationCitations,
        ]);
    }
}
