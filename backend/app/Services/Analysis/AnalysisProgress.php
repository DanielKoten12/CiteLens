<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisStep;
use App\Models\ResearchedDocument;
use App\Services\Document\DocumentAnalysisStateService;
use InvalidArgumentException;

/**
 * Monotonic progress reporter for one analysis run.
 *
 * Floors and ceilings per step come from `config('analysis.progress')`. Writes go
 * through {@see DocumentAnalysisStateService}, which enforces monotonicity and
 * the `0..100` bound; this class additionally bounds writes by only persisting
 * when the integer progress value changes (≤ 20 writes per step).
 *
 * The instance is transient (one per pipeline run), so the write cache never
 * leaks between documents; `begin()` resets it defensively anyway.
 */
final class AnalysisProgress
{
    /**
     * Last persisted progress per step value.
     *
     * @var array<string, int>
     */
    private array $lastWritten = [];

    public function __construct(
        private readonly DocumentAnalysisStateService $state,
    ) {}

    /**
     * Reset the run's write cache and record the `queued` step.
     */
    public function begin(ResearchedDocument $document): void
    {
        $this->lastWritten = [];

        $this->enter($document, AnalysisStep::Queued);
    }

    /**
     * Mark the start of a step at its configured floor.
     */
    public function enter(ResearchedDocument $document, AnalysisStep $step): void
    {
        $this->write($document, $step, $this->range($step)['floor'], force: true);
    }

    /**
     * Report intra-step progress from `$done / $total` items.
     *
     * A zero/negative total is ignored (nothing meaningful to report yet).
     */
    public function report(ResearchedDocument $document, AnalysisStep $step, int $done, int $total): void
    {
        if ($total <= 0) {
            return;
        }

        $range = $this->range($step);
        $ratio = max(0.0, min(1.0, $done / $total));
        $progress = $range['floor'] + (int) round(($range['ceiling'] - $range['floor']) * $ratio);

        $this->write($document, $step, $progress);
    }

    /**
     * Mark the end of a step at its configured ceiling.
     */
    public function leave(ResearchedDocument $document, AnalysisStep $step): void
    {
        $this->write($document, $step, $this->range($step)['ceiling'], force: true);
    }

    /**
     * Mark the whole pipeline as finished. The only path to progress `100`.
     */
    public function complete(ResearchedDocument $document): void
    {
        $this->state->complete($document);
    }

    /**
     * @return array{floor: int, ceiling: int}
     */
    private function range(AnalysisStep $step): array
    {
        $range = config("analysis.progress.{$step->value}");

        if (! is_array($range) || ! isset($range['floor'], $range['ceiling'])) {
            throw new InvalidArgumentException("Missing analysis progress range for step [{$step->value}].");
        }

        return [
            'floor' => (int) $range['floor'],
            'ceiling' => (int) $range['ceiling'],
        ];
    }

    private function write(ResearchedDocument $document, AnalysisStep $step, int $progress, bool $force = false): void
    {
        $previous = $this->lastWritten[$step->value] ?? PHP_INT_MIN;

        if (! $force && $progress <= $previous) {
            return;
        }

        $this->state->advance($document, $step, $progress);

        $this->lastWritten[$step->value] = max($previous, $progress);
    }
}
