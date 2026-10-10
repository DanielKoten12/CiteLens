<?php

namespace App\Services\Analysis\Steps;

use App\Enums\AnalysisStep;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\AnalysisProgress;
use App\Services\Analysis\CitationExtractionHints;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\Citation\CitationResolutionWriter;
use App\Services\Citations\CitationBatchResolver;
use App\Services\Citations\CitationMarkerParser;
use App\Services\Citations\CitationReference;
use App\Services\Citations\CitationResolution;
use App\Services\Citations\CitationResolutionInput;
use App\Services\Scoring\AuthorMatcher;
use Illuminate\Support\Facades\Log;

/**
 * Resolves every in-text citation to at most one bibliography reference of the
 * same document (`docs/API_SPEC.md` §10 step 6).
 *
 * Resolution is deterministic and pure until the final write:
 * - the extraction hint is consumed transiently when available (D-05.1-03);
 * - {@see CitationBatchResolver} decides pass 1 (hint/IEEE/APA) and pass 2
 *   (cross-citation evidence consolidation);
 * - the step rewrites pairings in one transaction so it stays idempotent.
 *
 * W1 consumes the extraction hint transiently; W2 persists the resolution
 * state/method/confidence/hint provenance through {@see CitationResolutionWriter}.
 */
final class ResolveCitationsStep implements PipelineStep
{
    public function __construct(
        private readonly CitationMarkerParser $parser,
        private readonly CitationBatchResolver $batchResolver,
        private readonly CitationResolutionWriter $writer,
        private readonly AuthorMatcher $authorMatcher,
        private readonly AnalysisProgress $progress,
    ) {}

    public function step(): AnalysisStep
    {
        return AnalysisStep::ResolvingCitations;
    }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        $citations = ResearchedDocumentCitation::query()
            ->where('researched_document_id', $document->getKey())
            ->get();

        if ($citations->isEmpty()) {
            return;
        }

        $references = $this->references($document);
        $hints = $context->hasExtractionHints() ? $context->extractionHints() : CitationExtractionHints::empty();

        $inputs = [];
        $hintIndexes = [];

        foreach ($citations as $citation) {
            $hintIndex = $hints->hintForCitation($citation->getKey());
            $hintIndexes[$citation->getKey()] = $hintIndex;

            $inputs[] = new CitationResolutionInput(
                citationId: $citation->getKey(),
                marker: $this->parser->parse($citation->citation_marker, $citation->citation_text),
                hintedReferenceId: $hints->referenceIdForIndex($hintIndex),
                hintIndex: $hintIndex,
            );
        }

        $resolutions = $this->batchResolver->resolve($references, $inputs);

        $this->writer->persistBatch($document, $resolutions, $hintIndexes);
        $this->progress->report($document, $this->step(), $citations->count(), $citations->count());
        $this->logResolution($document, $resolutions);
    }

    /**
     * Map the document's references to pure value objects in bibliography order.
     *
     * @return list<CitationReference>
     */
    private function references(ResearchedDocument $document): array
    {
        return $document->references()
            ->orderByRaw('text_start_offset IS NULL')
            ->orderBy('text_start_offset')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(fn ($reference, int $index): CitationReference => new CitationReference(
                id: $reference->getKey(),
                authors: $this->authorMatcher->names($reference->authors),
                publicationYear: $reference->publication_year,
                bibliographyIndex: $index + 1,
            ))
            ->all();
    }

    /**
     * @param  array<string, CitationResolution>  $resolutions
     */
    private function logResolution(ResearchedDocument $document, array $resolutions): void
    {
        $methods = [];

        foreach ($resolutions as $resolution) {
            $key = $resolution->method?->value ?? 'none';
            $methods[$key] = ($methods[$key] ?? 0) + 1;
        }

        Log::debug('Document citations resolved.', [
            'document_id' => $document->getKey(),
            'total' => count($resolutions),
            'methods' => $methods,
        ]);
    }
}
