<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisStep;
use App\Services\Analysis\Contracts\PipelineStep;
use InvalidArgumentException;

/**
 * Ordered registry of the pipeline steps available in this deployment.
 *
 * Registration order is irrelevant: {@see ordered()} sorts by the canonical
 * `AnalysisStep::ordered()` sequence, so Phases 04/05 append their steps to the
 * provider list without touching the runner. A duplicate or unknown step fails
 * loudly at the first run.
 */
final class AnalysisStepRegistry
{
    /**
     * @var list<PipelineStep>
     */
    private readonly array $steps;

    /**
     * @param  list<PipelineStep>  $steps
     */
    public function __construct(array $steps)
    {
        $this->steps = $steps;
    }

    /**
     * @return list<PipelineStep>
     *
     * @throws InvalidArgumentException on duplicate or unknown steps
     */
    public function ordered(): array
    {
        $order = array_flip(array_map(
            static fn (AnalysisStep $step): string => $step->value,
            AnalysisStep::ordered(),
        ));

        $seen = [];
        $sorted = [];

        foreach ($this->steps as $step) {
            $key = $step->step()->value;

            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Duplicate analysis step [{$key}] registered.");
            }

            if (! isset($order[$key])) {
                throw new InvalidArgumentException("Unknown analysis step [{$key}] registered.");
            }

            $seen[$key] = true;
            $sorted[$order[$key]] = $step;
        }

        ksort($sorted);

        return array_values($sorted);
    }
}
