<?php

namespace Database\Factories;

use App\Models\ResearchedDocumentReference;
use App\Models\ResearchedDocumentReferenceLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<ResearchedDocumentReferenceLocation>
 */
class ResearchedDocumentReferenceLocationFactory extends Factory
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
            'page_number' => 1,
            'x' => 72.0,
            'y' => 210.4,
            'width' => 451.2,
            'height' => 24.0,
            'page_width' => 595.0,
            'page_height' => 842.0,
            'coordinate_system' => 'pdf_points_top_left',
            'location_index' => 0,
        ];
    }

    /**
     * `location_index` must be unique per parent; advance it per created model.
     * Explicit `location_index` values passed to `create()` still win.
     */
    public function configure(): static
    {
        return $this->state(new Sequence(
            fn (Sequence $sequence): array => ['location_index' => $sequence->index],
        ));
    }

    public function page(int $pageNumber): static
    {
        return $this->state(fn (): array => ['page_number' => $pageNumber]);
    }
}
