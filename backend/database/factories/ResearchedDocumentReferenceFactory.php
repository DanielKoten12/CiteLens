<?php

namespace Database\Factories;

use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<ResearchedDocumentReference>
 */
class ResearchedDocumentReferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A reference has no DOI by default: the "valid reference without DOI" product
     * case is a first-class fixture. Use {@see self::withDoi()} to set one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'researched_document_id' => ResearchedDocument::factory(),
            'raw_text' => fake()->sentence(),
            'doi' => null,
            'title' => fake()->sentence(4),
            'authors' => fake()->name(),
            'publication_name' => fake()->company(),
            'publication_year' => fake()->numberBetween(1990, 2024),
            'text_start_offset' => 0,
            'text_end_offset' => 120,
        ];
    }

    /**
     * Advance the text offsets per created model (document order).
     */
    public function configure(): static
    {
        return $this->state(new Sequence(
            fn (Sequence $sequence): array => [
                'text_start_offset' => $sequence->index * 120,
                'text_end_offset' => ($sequence->index * 120) + 120,
            ],
        ));
    }

    public function withDoi(?string $doi = null): static
    {
        return $this->state(fn (): array => [
            'doi' => $doi ?? fake()->numerify('10.1000/####.####'),
        ]);
    }

    public function withoutDoi(): static
    {
        return $this->state(fn (): array => ['doi' => null]);
    }
}
