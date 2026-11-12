<?php

namespace App\Services\Metadata;

use App\Contracts\Metadata\SourceUnavailableException;
use App\Services\Metadata\Support\SourceCircuitBreaker;
use App\Services\Scoring\MatchReasonBuilder;
use Illuminate\Support\Facades\Log;

/**
 * Runs the ordered DOI resolution chain for one reference (D-09-03/D-09-16).
 *
 * Crossref is first and authoritative: when it answers, the fallback resolvers
 * are never called. A `404` is a definitive miss **for that resolver**, not for
 * the chain, so the next enabled resolver is tried. Garuda has no DOI endpoint
 * and is intentionally absent from the chain.
 *
 * The critical distinction:
 *
 * - every enabled resolver completed a miss → `NotFound` → `invalid`
 *   ("the DOI is broken");
 * - at least one enabled resolver could not answer (outage, circuit breaker or
 *   budget) and none resolved → `Unknown` → `pending`
 *   ("we could not check"). Never `invalid`.
 *
 * Holds no per-document state; shared state comes from {@see SourceChainState}.
 */
final class DoiResolutionChain
{
    public function __construct(
        private readonly MetadataSources $sources,
        private readonly SourceChainPolicy $policy,
        private readonly SourceCircuitBreaker $breaker,
    ) {}

    /**
     * @throws SourceUnavailableException when a fatal resolver fails before any completed exchange
     */
    public function resolve(
        string $normalizedDoi,
        SourceChainState $state,
        ?string $referenceId = null,
    ): DoiResolutionResult {
        $unknown = false;
        $enabledResolvers = 0;

        foreach ($this->sources->doiResolvers as $resolver) {
            if (! $resolver->enabled()) {
                // Disabled is not an outage: it is simply not part of the chain.
                continue;
            }

            $enabledResolvers++;

            $source = $resolver->source();
            $fallback = $this->sources->isFallback($source);

            if ($state->isUnavailable($source)
                || ($this->policy->isDegradable($source) && $this->breaker->isOpen($source))
                || $this->policy->budgetExhausted($source, $state, $fallback)) {
                $unknown = true;

                continue;
            }

            $state->recordLookup($source, $fallback);

            try {
                $work = $resolver->resolve($normalizedDoi);
                $state->recordResponse($source);

                if ($this->policy->isDegradable($source)) {
                    $this->breaker->recordSuccess($source);
                }

                if ($work !== null) {
                    // The endpoint is keyed by DOI: the normalized input is proof
                    // of resolution even when a mapper omits the field.
                    return new DoiResolutionResult(
                        lookup: DoiLookup::Resolved,
                        work: $work->withDoi($normalizedDoi),
                        source: $source,
                    );
                }

                // A completed null answer is a definitive miss for this resolver.
            } catch (SourceUnavailableException $exception) {
                $unknown = true;
                $state->recordFailure($source);
                $this->logFailure($exception, $state, $referenceId);

                if ($this->policy->isFatal($source) && ! $state->hasResponded($source)) {
                    throw $exception;
                }

                if ($this->policy->isDegradable($source)) {
                    $this->breaker->recordFailure($source);
                    $state->markUnavailable($source);
                }
            }
        }

        // With no enabled resolver at all, "not found" would be a claim we cannot
        // support: report the DOI as unchecked instead.
        $unchecked = $unknown || $enabledResolvers === 0;

        return new DoiResolutionResult(
            lookup: $unchecked ? DoiLookup::Unknown : DoiLookup::NotFound,
            pendingReason: $unchecked ? MatchReasonBuilder::DOI_UNKNOWN : null,
        );
    }

    private function logFailure(
        SourceUnavailableException $exception,
        SourceChainState $state,
        ?string $referenceId,
    ): void {
        Log::warning('Metadata DOI resolution failed.', [
            'source' => $exception->source()->value,
            'operation' => 'doi',
            'document_id' => $state->documentId(),
            'reference_id' => $referenceId,
            'reason' => $exception->reason(),
            'exception_class' => $exception::class,
        ]);
    }
}
