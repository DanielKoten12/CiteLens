<?php

namespace App\Services\Metadata;

use App\Enums\MetadataSource;

/**
 * Mutable per-document state shared by the DOI resolution and search chains.
 *
 * Created inside `ValidateReferencesStep::handle()` and never stored on a step
 * property, so state cannot leak between documents when the container reuses a
 * step instance.
 *
 * The state records what actually happened during the run: per-source lookups
 * and completed exchanges (the basis of the fatal-first-failure policy),
 * sources already given up on (degradable sources only), and the document-wide
 * fallback budget that covers both search and DOI calls of optional sources.
 */
final class SourceChainState
{
    /** @var array<string, int> */
    private array $lookups = [];

    /** @var array<string, true> */
    private array $responded = [];

    /** @var array<string, true> */
    private array $unavailable = [];

    /** @var array<string, true> */
    private array $consulted = [];

    private int $fallbackLookups = 0;

    private readonly float $startedAt;

    /**
     * @param  int  $fallbackBudget  document-wide fallback lookups, 0 = unlimited
     * @param  int  $fallbackSeconds  document-wide fallback wall-clock cap, 0 = unlimited
     */
    public function __construct(
        private readonly string $documentId,
        private readonly int $fallbackBudget = 300,
        private readonly int $fallbackSeconds = 300,
        ?float $startedAt = null,
    ) {
        // Monotonic start of the run; tests may inject an earlier value.
        $this->startedAt = $startedAt ?? $this->now();
    }

    public function documentId(): string
    {
        return $this->documentId;
    }

    /**
     * Record an attempted lookup for a source (`cache hits included`).
     */
    public function recordLookup(MetadataSource $source, bool $fallback = true): void
    {
        $this->lookups[$source->value] = $this->lookups($source) + 1;
        $this->consulted[$source->value] = true;

        if ($fallback) {
            $this->fallbackLookups++;
        }
    }

    public function lookups(MetadataSource $source): int
    {
        return $this->lookups[$source->value] ?? 0;
    }

    /**
     * Record a completed exchange (a hit **or** a definitive miss).
     *
     * A completed exchange proves reachability: later failures of a fatal
     * source degrade the affected reference instead of failing the document
     * (OQ-03/D-08-03).
     */
    public function recordResponse(MetadataSource $source): void
    {
        $this->responded[$source->value] = true;
        $this->consulted[$source->value] = true;
    }

    public function hasResponded(MetadataSource $source): bool
    {
        return isset($this->responded[$source->value]);
    }

    /**
     * Record that a source was attempted and could not answer. The failure
     * reason is per-reference and stays in the chain that observed it.
     */
    public function recordFailure(MetadataSource $source): void
    {
        $this->consulted[$source->value] = true;
    }

    public function markUnavailable(MetadataSource $source): void
    {
        $this->unavailable[$source->value] = true;
    }

    public function isUnavailable(MetadataSource $source): bool
    {
        return isset($this->unavailable[$source->value]);
    }

    public function fallbackBudgetExhausted(): bool
    {
        return $this->fallbackBudget > 0 && $this->fallbackLookups >= $this->fallbackBudget;
    }

    public function fallbackTimeExhausted(): bool
    {
        if ($this->fallbackSeconds <= 0) {
            return false;
        }

        return ($this->now() - $this->startedAt) >= $this->fallbackSeconds;
    }

    /**
     * Sources the run actually attempted, in first-attempt order.
     *
     * @return list<MetadataSource>
     */
    public function consulted(): array
    {
        $sources = [];

        foreach (array_keys($this->consulted) as $value) {
            $source = MetadataSource::tryFrom((string) $value);

            if ($source !== null) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    private function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
