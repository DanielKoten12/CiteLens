<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\ResearchedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fileable_type' => 'researched_document',
            'fileable_id' => ResearchedDocument::factory(),
            'filename' => fake()->word().'.pdf',
            'path' => 'documents/'.Str::uuid().'/'.Str::uuid().'.pdf',
            'disk' => (string) config('filesystems.default'),
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 5_000_000),
        ];
    }
}
