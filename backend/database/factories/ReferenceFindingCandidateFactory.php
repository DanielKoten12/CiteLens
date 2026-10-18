<?php

namespace Database\Factories;

use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<ReferenceFindingCandidate>
 */
class ReferenceFindingCandidateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_finding_id' => ReferenceFinding::factory(),
            'rank' => 1,
            'confidence' => 0.5,
            'doi' => fake()->numerify('10.1000/####.####'),
            'title' => fake()->sentence(4),
            'authors' => fake()->name(),
            'publication_name' => fake()->company(),
            'publication_year' => fake()->numberBetween(1990, 2024),
            'url' => null,
            'match_reason' => null,
        ];
    }

    /**
     * `rank` is unique per finding and ordered best first (1..N).
     */
    public function configure(): static
    {
        return $this->state(new Sequence(
            fn (Sequence $sequence): array => ['rank' => $sequence->index + 1],
        ));
    }
}
