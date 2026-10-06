<?php

namespace Database\Factories;

use App\Enums\ReferenceFindingStatus;
use App\Models\ReferenceFinding;
use App\Models\ResearchedDocumentReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferenceFinding>
 */
class ReferenceFindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'researched_document_reference_id' => ResearchedDocumentReference::factory(),
            'researched_document_id' => fn (array $attributes): string => (string) ResearchedDocumentReference::query()
                ->whereKey($attributes['researched_document_reference_id'])
                ->value('researched_document_id'),
            'selected_candidate_id' => null,
            'status' => ReferenceFindingStatus::Pending,
            'confidence' => null,
            'reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'is_manual' => false,
        ];
    }

    /**
     * Create a finding for an existing reference, keeping both ids consistent.
     */
    public function forReference(ResearchedDocumentReference $reference): static
    {
        return $this->state(fn (): array => [
            'researched_document_id' => $reference->researched_document_id,
            'researched_document_reference_id' => $reference->getKey(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => ReferenceFindingStatus::Pending,
            'confidence' => null,
            'reason' => null,
            'selected_candidate_id' => null,
        ]);
    }

    public function valid(): static
    {
        return $this->state(fn (): array => [
            'status' => ReferenceFindingStatus::Valid,
            'confidence' => 0.95,
            'reason' => 'DOI cocok dengan metadata Crossref.',
        ]);
    }

    public function suspicious(): static
    {
        return $this->state(fn (): array => [
            'status' => ReferenceFindingStatus::Suspicious,
            'confidence' => 0.63,
            'reason' => 'Judul pada metadata Crossref memiliki perbedaan.',
        ]);
    }

    public function invalid(): static
    {
        return $this->state(fn (): array => [
            'status' => ReferenceFindingStatus::Invalid,
            'confidence' => 0.72,
            'reason' => 'Metadata tidak sesuai dengan yang tercatat.',
        ]);
    }

    public function notFound(): static
    {
        return $this->state(fn (): array => [
            'status' => ReferenceFindingStatus::NotFound,
            'confidence' => null,
            'reason' => 'Tidak ada kandidat ditemukan di Crossref.',
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (): array => [
            'is_manual' => true,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }
}
