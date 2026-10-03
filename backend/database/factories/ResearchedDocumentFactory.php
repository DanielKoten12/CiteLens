<?php

namespace Database\Factories;

use App\Models\ResearchedDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResearchedDocument>
 */
class ResearchedDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true).'.pdf',
            'status' => 'pending',
            'analysis_progress' => 0,
            'analysis_step' => 'queued',
        ];
    }
}
