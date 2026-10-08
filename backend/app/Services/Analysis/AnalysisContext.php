<?php

namespace App\Services\Analysis;

use App\Data\Inference\ExtractionResultData;
use LogicException;

/**
 * Transient artifacts shared between pipeline steps within one run.
 *
 * Only data that does not (and should not) live in the database travels here:
 * the extraction payload is consumed and cleared by the persistence step, and
 * Phase 04 adds the in-memory embeddings. Each artifact has a typed accessor so
 * a missing dependency fails loudly instead of silently operating on `null`.
 */
final class AnalysisContext
{
    private ?ExtractionResultData $extraction = null;

    public function setExtraction(ExtractionResultData $extraction): void
    {
        $this->extraction = $extraction;
    }

    public function hasExtraction(): bool
    {
        return $this->extraction !== null;
    }

    /**
     * @throws LogicException when no extraction has been set by an earlier step
     */
    public function extraction(): ExtractionResultData
    {
        return $this->extraction ?? throw new LogicException('No extraction result is available on the analysis context.');
    }

    /**
     * Consume the extraction result and clear it, freeing the payload after
     * persistence has written it to the database.
     *
     * @throws LogicException when no extraction has been set by an earlier step
     */
    public function takeExtraction(): ExtractionResultData
    {
        $extraction = $this->extraction();

        $this->extraction = null;

        return $extraction;
    }
}
