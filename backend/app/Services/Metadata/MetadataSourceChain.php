<?php

namespace App\Services\Metadata;

use App\Contracts\Metadata\SourceUnavailableException;
use App\Enums\MetadataSource;
use App\Services\Metadata\Support\SourceCircuitBreaker;
use App\Services\Scoring\MatchReasonBuilder;
use Illuminate\Support\Facades\Log;

/**
 * Runs the ordered metadata search chain for one reference (D-09-01/05).
 *
 * Primary sources always run. Fallback sources run in configured order until
 * the stop policy is satisfied. The chain holds no per-document state: it reads
 * it from the supplied {@see SourceChainState}, so it can never leak between
 * documents or references.
 *
 * Failure semantics:
 *
 * - A source that is disabled is simply absent from the chain (not an outage).
 * - A `fatal` source's **first** failure of the run is rethrown (OQ-03); a later
 *   failure is recorded and the chain continues, so it cannot fabricate a
 *   `not_found`.
 * - A `degrade` source is marked unavailable for the rest of the run and opens
 *   the circuit breaker after repeated failures.
 * - With no candidates and any failure/skip, the result is transient (`pending`,
 *   never `not_found`). With a candidate the verdict proceeds normally.
 */
final class MetadataSourceChain
{
    public function __construct(
        private readonly MetadataSources $sources,
        private readonly SourceChainPolicy $policy,
        private readonly WorkMetadataMerger $merger,
        private readonly SourceCircuitBreaker $breaker,
        private readonly MatchReasonBuilder $reasons,
    ) {}

    /**
     * @param  list<WorkMetadata>  $existing  candidates already known (e.g. the DOI work)
     *
     * @throws SourceUnavailableException when a fatal source fails before any completed exchange
     */
    public function search(
        ReferenceQuery $query,
        ?string $referenceTitle,
        array $existing,
        SourceChainState $state,
        ?string $referenceId = null,
    ): SourceChainResult {
        $candidates = [];
        $failure = null;

        foreach ($this->sources->primary as $source) {
            if (! $source->enabled()) {
                continue;
            }

            $skip = $this->skipReason($source->source(), $state, fallback: false);

            if ($skip !== null) {
                $failure ??= $skip;

                continue;
            }

            $state->recordLookup($source->source(), fallback: false);

            try {
                $candidates = $this->merger->merge($candidates, $source->search($query));
                $state->recordResponse($source->source());
                $this->recordSuccess($source->source());
            } catch (SourceUnavailableException $exception) {
                $failure ??= $this->reasons->transient($exception->source());
                $state->recordFailure($exception->source());
                $this->logFailure('search', $exception, $state, $referenceId);

                if ($this->policy->isFatal($exception->source()) && ! $state->hasResponded($exception->source())) {
                    throw $exception;
                }

                $this->recordDegradation($exception->source(), $state);
            }
        }

        // The DOI work is an ordinary candidate and keeps its source order: it is
        // merged in front of the search results (D-09-09).
        $candidates = $this->merger->merge($existing, $candidates);

        if (! $this->policy->shouldGate($referenceTitle) || $this->policy->isSatisfied($referenceTitle, $candidates)) {
            return $this->result($candidates, $failure, $state);
        }

        foreach ($this->sources->fallback as $source) {
            if ($this->policy->isSatisfied($referenceTitle, $candidates)) {
                break;
            }

            if (! $source->enabled()) {
                continue;
            }

            $skip = $this->skipReason($source->source(), $state, fallback: true);

            if ($skip !== null) {
                $failure ??= $skip;

                continue;
            }

            $state->recordLookup($source->source(), fallback: true);

            try {
                $candidates = $this->merger->merge($candidates, $source->search($query));
                $state->recordResponse($source->source());
                $this->recordSuccess($source->source());
            } catch (SourceUnavailableException $exception) {
                $failure ??= $this->reasons->transient($exception->source());
                $state->recordFailure($exception->source());
                $this->logFailure('search', $exception, $state, $referenceId);

                if ($this->policy->isFatal($exception->source()) && ! $state->hasResponded($exception->source())) {
                    throw $exception;
                }

                $this->recordDegradation($exception->source(), $state);
            }
        }

        return $this->result($candidates, $failure, $state);
    }

    /**
     * Why a source must be skipped for this reference, or `null` to call it.
     *
     * A disabled source never reaches this method. Every skip here is an
     * outage/budget condition, so it produces a pending reason if nothing else
     * answers.
     */
    private function skipReason(MetadataSource $source, SourceChainState $state, bool $fallback): ?string
    {
        if ($state->isUnavailable($source)) {
            return $this->reasons->transient($source);
        }

        if ($this->policy->isDegradable($source) && $this->breaker->isOpen($source)) {
            return $this->reasons->transient($source);
        }

        if ($this->policy->budgetExhausted($source, $state, $fallback)) {
            return $this->reasons->budget($source);
        }

        return null;
    }

    private function recordSuccess(MetadataSource $source): void
    {
        if ($this->policy->isDegradable($source)) {
            $this->breaker->recordSuccess($source);
        }
    }

    private function recordDegradation(MetadataSource $source, SourceChainState $state): void
    {
        if (! $this->policy->isDegradable($source)) {
            return;
        }

        $this->breaker->recordFailure($source);
        $state->markUnavailable($source);
    }

    /**
     * @param  list<WorkMetadata>  $candidates
     */
    private function result(array $candidates, ?string $failure, SourceChainState $state): SourceChainResult
    {
        $transient = $candidates === [] && $failure !== null;

        return new SourceChainResult(
            candidates: $candidates,
            transientFailure: $transient,
            pendingReason: $transient ? $failure : null,
            consultedSources: $state->consulted(),
        );
    }

    private function logFailure(
        string $operation,
        SourceUnavailableException $exception,
        SourceChainState $state,
        ?string $referenceId,
    ): void {
        Log::warning('Metadata source lookup failed.', [
            'source' => $exception->source()->value,
            'operation' => $operation,
            'document_id' => $state->documentId(),
            'reference_id' => $referenceId,
            'reason' => $exception->reason(),
            'exception_class' => $exception::class,
        ]);
    }
}
