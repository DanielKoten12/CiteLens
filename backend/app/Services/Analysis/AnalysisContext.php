<?php

namespace App\Services\Analysis;

use App\Data\Inference\ExtractionResultData;
use LogicException;

/**
 * Transient artifacts shared between pipeline steps within one run.
 *
 * Only data that does not (and should not) live in the database travels here:
 * the extraction payload is consumed and cleared by the persistence step, and
 * Phase 04 adds the Crossref verification batch and the embedding index. Each
 * artifact has a typed accessor so a missing dependency fails loudly instead of
 * silently operating on `null`.
 */
final class AnalysisContext
{
    private ?ExtractionResultData $extraction = null;

    private ?ReferenceVerificationBatch $verification = null;

    private ?EmbeddingIndex $embeddings = null;

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

    public function setVerification(ReferenceVerificationBatch $verification): void
    {
        $this->verification = $verification;
    }

    public function hasVerification(): bool
    {
        return $this->verification !== null;
    }

    /**
     * @throws LogicException when no verification batch has been set by an earlier step
     */
    public function verification(): ReferenceVerificationBatch
    {
        return $this->verification ?? throw new LogicException('No reference verification batch is available on the analysis context.');
    }

    /**
     * Consume the verification batch and clear it, freeing the Crossref payload
     * once scoring has persisted its verdicts.
     *
     * @throws LogicException when no verification batch has been set by an earlier step
     */
    public function takeVerification(): ReferenceVerificationBatch
    {
        $verification = $this->verification();

        $this->verification = null;

        return $verification;
    }

    public function setEmbeddings(EmbeddingIndex $embeddings): void
    {
        $this->embeddings = $embeddings;
    }

    public function hasEmbeddings(): bool
    {
        return $this->embeddings !== null;
    }

    /**
     * @throws LogicException when no embedding index has been set by an earlier step
     */
    public function embeddings(): EmbeddingIndex
    {
        return $this->embeddings ?? throw new LogicException('No embedding index is available on the analysis context.');
    }
}
