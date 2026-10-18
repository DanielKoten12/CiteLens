<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\GeneratedDocumentReport;
use App\Models\ResearchedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedDocumentReport>
 */
class GeneratedDocumentReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'researched_document_id' => ResearchedDocument::factory()->completed(),
            'reference_finding_id' => null,
            'file_id' => null,
            'status' => ReportStatus::Pending,
            'error' => null,
            'generated_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportStatus::Pending,
            'error' => null,
            'generated_at' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportStatus::Processing,
            'error' => null,
            'generated_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportStatus::Completed,
            'error' => null,
            'generated_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportStatus::Failed,
            'error' => 'Laporan gagal dibuat. Silakan coba lagi.',
            'generated_at' => null,
        ]);
    }
}
