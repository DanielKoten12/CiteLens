<?php

namespace App\Services\Analysis;

/**
 * The Crossref retrieval results for one analysis run, in bibliography order.
 */
final class ReferenceVerificationBatch
{
    /**
     * @param  list<ReferenceVerification>  $verifications
     */
    public function __construct(
        public readonly array $verifications,
    ) {}

    public function isEmpty(): bool
    {
        return $this->verifications === [];
    }

    public function count(): int
    {
        return count($this->verifications);
    }
}
