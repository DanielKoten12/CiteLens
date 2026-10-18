<?php

namespace App\Services\Analysis\Contracts;

use App\Jobs\AnalyzeDocumentJob;
use App\Models\ResearchedDocument;

/**
 * Runs the canonical analysis pipeline for a document and drives it to a
 * terminal state (`completed` or `failed`).
 *
 * This is the seam between the queue transport ({@see AnalyzeDocumentJob})
 * and the pipeline implementation. The concrete pipeline — extraction,
 * Crossref validation, scoring, citation resolution — is implemented in
 * Phase 03 and bound in a service provider.
 */
interface RunsDocumentAnalysis
{
    /**
     * Execute the pipeline for the given document.
     */
    public function run(ResearchedDocument $document): void;
}
