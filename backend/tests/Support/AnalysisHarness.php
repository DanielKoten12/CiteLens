<?php

namespace Tests\Support;

use App\Models\File;
use App\Services\Analysis\AnalysisStepRegistry;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\Analysis\Steps\EmbedReferencesStep;
use App\Services\Analysis\Steps\ExtractDocumentStep;
use App\Services\Analysis\Steps\FinalizeAnalysisStep;
use App\Services\Analysis\Steps\PersistExtractionStep;
use App\Services\Analysis\Steps\ResolveCitationsStep;
use App\Services\Analysis\Steps\ScoreReferencesStep;
use App\Services\Analysis\Steps\ValidateReferencesStep;
use Illuminate\Support\Facades\Storage;

/**
 * Shared setup for analysis-pipeline tests.
 *
 * Builds a document with a real stored PDF and assembles the pipeline step list
 * (the real production steps), so tests never depend on global helper functions
 * or a production stub.
 */
final class AnalysisHarness
{
    /**
     * A document with one stored PDF ready for extraction.
     */
    public static function document(): DocumentTree
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/test/doc.pdf', '%PDF-1.4 fake');

        $tree = DocumentTree::create();

        File::factory()->for($tree->document, 'fileable')->create([
            'filename' => 'doc.pdf',
            'path' => 'documents/test/doc.pdf',
            'mime_type' => 'application/pdf',
        ]);

        return $tree;
    }

    /**
     * The production step list with the real citation-resolution step, so the
     * full canonical sequence and progress range can be exercised.
     *
     * @return list<PipelineStep>
     */
    public static function fullSteps(): array
    {
        return [
            app(ExtractDocumentStep::class),
            app(PersistExtractionStep::class),
            app(ValidateReferencesStep::class),
            app(EmbedReferencesStep::class),
            app(ScoreReferencesStep::class),
            app(ResolveCitationsStep::class),
            app(FinalizeAnalysisStep::class),
        ];
    }

    /**
     * Replace the container's step registry for the current test.
     *
     * @param  list<PipelineStep>  $steps
     */
    public static function useSteps(array $steps): void
    {
        app()->instance(AnalysisStepRegistry::class, new AnalysisStepRegistry($steps));
    }
}
