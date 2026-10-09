<?php

namespace App\Services\Analysis\Steps;

use App\Enums\AnalysisStep;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\AnalysisProgress;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\Citations\CitationMarkerParser;
use App\Services\Citations\CitationReference;
use App\Services\Citations\CitationResolver;
use Illuminate\Support\Facades\DB;

/**
 * Resolves every in-text citation to at most one bibliography reference of the
 * same document (`docs/API_SPEC.md` §10 step 6, proposal scope APA + IEEE).
 *
 * Resolution is marker-based (D-05-01): the GROBID `reference_index` hint is not
 * persisted and is intentionally not used. References are mapped once to
 * {@see CitationReference} value objects in the canonical bibliography order
 * (`text_start_offset` ASC, nulls last, `id` tiebreak — OQ-18) so IEEE ordinals
 * are deterministic and the resolver stays pure.
 *
 * The step is idempotent: within one transaction it clears every pairing of the
 * document and rewrites it from scratch, so running it twice leaves the same
 * state. Pairing is independent of the reference verdict — citation status is
 * derived later from the pairing plus the finding (never stored).
 */
final class ResolveCitationsStep implements PipelineStep
{
    public function __construct(
        private readonly CitationMarkerParser $parser,
        private readonly CitationResolver $resolver,
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
        $pairings = [];
        $total = $citations->count();

        foreach ($citations as $index => $citation) {
            $marker = $this->parser->parse($citation->citation_marker, $citation->citation_text);
            $resolution = $this->resolver->resolve($marker, $references);

            if ($resolution->referenceId !== null) {
                $pairings[$resolution->referenceId][] = $citation->getKey();
            }

            $this->progress->report($document, $this->step(), $index + 1, $total);
        }

        DB::transaction(function () use ($document, $pairings): void {
            // Reset first so a removed pairing cannot survive a re-run.
            ResearchedDocumentCitation::query()
                ->where('researched_document_id', $document->getKey())
                ->update(['researched_document_reference_id' => null]);

            foreach ($pairings as $referenceId => $citationIds) {
                ResearchedDocumentCitation::query()
                    ->whereIn('id', $citationIds)
                    ->update(['researched_document_reference_id' => $referenceId]);
            }
        });
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
            ->map(fn (ResearchedDocumentReference $reference, int $index): CitationReference => new CitationReference(
                id: $reference->getKey(),
                authors: $reference->authors,
                publicationYear: $reference->publication_year,
                bibliographyIndex: $index + 1,
            ))
            ->all();
    }
}
