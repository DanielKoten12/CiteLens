<?php

namespace App\Services\Analysis;

/**
 * Transient cosine-similarity input produced by {@see Steps\EmbedReferencesStep}
 * and consumed by the scoring step.
 *
 * Vectors are keyed by reference/candidate identity, never persisted (the
 * canonical schema has no embedding column). `unavailable()` represents a
 * degraded SBERT run (OQ-04): the scorer then drops the semantic signal and
 * records the degradation in the finding reason.
 */
final class EmbeddingIndex
{
    /**
     * @param  array<string, list<float>>  $vectors
     */
    private function __construct(
        private readonly array $vectors,
        private readonly bool $available,
    ) {}

    /**
     * @param  array<string, list<float>>  $vectors
     */
    public static function fromVectors(array $vectors): self
    {
        return new self($vectors, true);
    }

    public static function unavailable(): self
    {
        return new self([], false);
    }

    public static function empty(): self
    {
        return new self([], true);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function hasVectors(): bool
    {
        return $this->vectors !== [];
    }

    /**
     * @return list<float>|null
     */
    public function vectorFor(string $key): ?array
    {
        return $this->vectors[$key] ?? null;
    }

    public static function referenceKey(string $referenceId): string
    {
        return "r:{$referenceId}";
    }

    public static function candidateKey(string $referenceId, int $index): string
    {
        return "r:{$referenceId}:c:{$index}";
    }
}
