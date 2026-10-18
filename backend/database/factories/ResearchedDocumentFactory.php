<?php

namespace Database\Factories;

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
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
            'status' => DocumentStatus::Pending,
            'analysis_progress' => 0,
            'analysis_step' => AnalysisStep::Queued,
            'analysis_error' => null,
            'analysis_started_at' => null,
            'analysis_completed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Pending,
            'analysis_progress' => 0,
            'analysis_step' => AnalysisStep::Queued,
            'analysis_error' => null,
            'analysis_started_at' => null,
            'analysis_completed_at' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Processing,
            'analysis_progress' => 40,
            'analysis_step' => AnalysisStep::Extracting,
            'analysis_error' => null,
            'analysis_started_at' => now()->subMinutes(2),
            'analysis_completed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Completed,
            'analysis_progress' => 100,
            'analysis_step' => AnalysisStep::Completed,
            'analysis_error' => null,
            'analysis_started_at' => now()->subMinutes(5),
            'analysis_completed_at' => now()->subMinute(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Failed,
            'analysis_progress' => 35,
            'analysis_step' => AnalysisStep::CrossrefValidation,
            'analysis_error' => 'Analisis dokumen gagal. Silakan coba lagi.',
            'analysis_started_at' => now()->subMinutes(3),
            'analysis_completed_at' => null,
        ]);
    }
}
