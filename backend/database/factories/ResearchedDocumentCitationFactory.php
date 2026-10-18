<?php

namespace Database\Factories;

use App\Enums\CitationResolutionState;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResearchedDocumentCitation>
 */
class ResearchedDocumentCitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Unpaired by default: `hallucination` is a first-class product case. Use
     * {@see self::pairedTo()} to pair it with a reference of the same document.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'researched_document_id' => ResearchedDocument::factory(),
            'researched_document_reference_id' => null,
            'resolution_state' => CitationResolutionState::Unmatched->value,
            'citation_text' => '(Smith, 2020)',
            'citation_marker' => null,
            'context_before' => null,
            'context_after' => null,
            'text_start_offset' => null,
            'text_end_offset' => null,
            'occurrence_index' => null,
        ];
    }

    /**
     * Pair the citation with a reference; both the document and reference ids come
     * from the same reference so cross-document pairings cannot be created by accident.
     */
    public function pairedTo(ResearchedDocumentReference $reference): static
    {
        return $this->state(fn (): array => [
            'researched_document_id' => $reference->researched_document_id,
            'researched_document_reference_id' => $reference->getKey(),
            'resolution_state' => CitationResolutionState::Paired->value,
        ]);
    }
}
