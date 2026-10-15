<?php

namespace Database\Factories;

use App\Enums\CitationResolutionMethod;
use App\Models\CitationResolutionCandidate;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<CitationResolutionCandidate>
 */
class CitationResolutionCandidateFactory extends Factory
{
    protected $model = CitationResolutionCandidate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'citation_id' => ResearchedDocumentCitation::factory(),
            'researched_document_reference_id' => ResearchedDocumentReference::factory(),
            'rank' => 1,
            'confidence' => 0.5,
            'method' => CitationResolutionMethod::Apa->value,
            'match_reason' => null,
        ];
    }

    /**
     * `rank` is unique per citation and ordered best first (1..N).
     */
    public function configure(): static
    {
        return $this->state(new Sequence(
            fn (Sequence $sequence): array => ['rank' => $sequence->index + 1],
        ));
    }
}
