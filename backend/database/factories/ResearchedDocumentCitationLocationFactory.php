<?php

namespace Database\Factories;

use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentCitationLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<ResearchedDocumentCitationLocation>
 */
class ResearchedDocumentCitationLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'citation_id' => ResearchedDocumentCitation::factory(),
            'page_number' => 1,
            'x' => 88.0,
            'y' => 410.0,
            'width' => 96.0,
            'height' => 11.0,
            'page_width' => 595.0,
            'page_height' => 842.0,
            'coordinate_system' => 'pdf_points_top_left',
            'location_index' => 0,
        ];
    }

    /**
     * `location_index` must be unique per citation; advance it per created model.
     */
    public function configure(): static
    {
        return $this->state(new Sequence(
            fn (Sequence $sequence): array => ['location_index' => $sequence->index],
        ));
    }
}
