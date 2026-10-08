<?php

namespace Tests\Support;

use App\Enums\AnalysisStep;
use App\Models\ResearchedDocument;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\Contracts\PipelineStep;
use Closure;

/**
 * Closure-backed pipeline step for tests.
 *
 * Lets a test register the canonical step sequence without a production stub and
 * observe/inject behaviour at any point (order, progress, failures, deletion).
 */
final class StubPipelineStep implements PipelineStep
{
    /**
     * @param  (Closure(ResearchedDocument, AnalysisContext): void)|null  $handler
     */
    public function __construct(
        private readonly AnalysisStep $step,
        private readonly ?Closure $handler = null,
    ) {}

    /**
     * @param  (Closure(ResearchedDocument, AnalysisContext): void)|null  $handler
     */
    public static function for(AnalysisStep $step, ?Closure $handler = null): self
    {
        return new self($step, $handler);
    }

    public function step(): AnalysisStep
    {
        return $this->step;
    }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        if ($this->handler !== null) {
            ($this->handler)($document, $context);
        }
    }
}
