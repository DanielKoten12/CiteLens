<?php

namespace App\Services\Analysis\Steps;

use App\Contracts\Metadata\BibliographicSource;
use App\Enums\AnalysisStep;
use App\Exceptions\CrossrefUnavailableException;
use App\Exceptions\GarudaUnavailableException;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentReference;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\AnalysisProgress;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\Analysis\ReferenceVerification;
use App\Services\Analysis\ReferenceVerificationBatch;
use App\Services\Analysis\VerificationRunState;
use App\Services\Crossref\CrossrefClient;
use App\Services\Crossref\DoiLookup;
use App\Services\Crossref\DoiNormalizer;
use App\Services\Garuda\GarudaClient;
use App\Services\Metadata\ReferenceQuery;
use App\Services\Metadata\WorkMetadata;
use App\Services\Scoring\MatchReasonBuilder;
use App\Services\Scoring\ScoringConfig;
use App\Services\Scoring\ScoringReference;
use App\Services\Scoring\StringSimilarity;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * `crossref_validation` — retrieves candidate metadata for every reference.
 *
 * Performs network I/O only: durable rows are written by `ScoreReferencesStep`
 * after scoring (D-04-05). References are processed in bibliography order
 * (`text_start_offset` ASC, nulls last, `id` tiebreak — OQ-18).
 *
 * Primary sources (config `analysis.metadata.primary_sources`) run first;
 * Garuda is consulted only when no primary candidate is a plausible title match
 * (`docs/plans/backend/08-garuda-fallback-source.md`).
 *
 * Outage policy (D-04-01 / OQ-03, D-08-03): a completed primary exchange (a
 * lookup hit or a `404` miss) proves reachability. A `CrossrefUnavailableException`
 * before any completed exchange is rethrown and fails the document; after one,
 * only that reference degrades to `pending`. Garuda follows the same shape with
 * its own reason strings.
 */
final class ValidateReferencesStep implements PipelineStep
{
    public function __construct(
        private readonly CrossrefClient $client,
        private readonly DoiNormalizer $normalizer,
        private readonly AnalysisProgress $progress,
        private readonly GarudaClient $garuda,
        private readonly StringSimilarity $strings,
        private readonly ScoringConfig $scoring,
    ) {}

    public function step(): AnalysisStep
    {
        return AnalysisStep::CrossrefValidation;
    }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        $references = $document->references()
            ->orderByRaw('text_start_offset IS NULL')
            ->orderBy('text_start_offset')
            ->orderBy('id')
            ->get();

        $state = new VerificationRunState;
        $primarySources = $this->primarySources();
        $verifications = [];
        $total = $references->count();

        foreach ($references as $index => $reference) {
            $verifications[] = $this->verifyReference($reference, $primarySources, $state);

            $this->progress->report($document, $this->step(), $index + 1, $total);
        }

        $context->setVerification(new ReferenceVerificationBatch($verifications));
    }

    /**
     * @param  list<BibliographicSource>  $primarySources
     */
    private function verifyReference(
        ResearchedDocumentReference $reference,
        array $primarySources,
        VerificationRunState $state,
    ): ReferenceVerification {
        $referenceValue = ScoringReference::fromModel($reference, $this->normalizer);
        $doiLookup = DoiLookup::NotPresent;
        $candidates = [];

        if ($referenceValue->doi !== null) {
            if (! $referenceValue->doiValid) {
                $doiLookup = DoiLookup::Malformed;
            } else {
                try {
                    $work = $this->client->findByDoi($referenceValue->doi);
                    $state->primaryResponded = true;

                    if ($work === null) {
                        $doiLookup = DoiLookup::NotFound;
                    } else {
                        $doiLookup = DoiLookup::Resolved;
                        $candidates[] = $work;
                    }
                } catch (CrossrefUnavailableException $exception) {
                    if (! $state->primaryResponded) {
                        throw $exception;
                    }

                    return new ReferenceVerification($referenceValue, DoiLookup::NotPresent, [], transientFailure: true);
                }
            }
        }

        // Best-effort bibliographic search whenever there is data to search on
        // (D-04-02): it supplies the correct-publication candidate for a
        // conflicting/non-resolving DOI and the candidates for a no-DOI entry.
        $searchQuery = ReferenceQuery::fromReference($reference);

        if ($searchQuery->bibliographic !== '') {
            foreach ($primarySources as $source) {
                try {
                    $candidates = $this->merge($candidates, $source->search($searchQuery));
                    $state->primaryResponded = true;
                } catch (CrossrefUnavailableException $exception) {
                    if (! $state->primaryResponded) {
                        throw $exception;
                    }

                    return new ReferenceVerification($referenceValue, $doiLookup, [], transientFailure: true);
                }
            }
        }

        if ($this->needsFallback($referenceValue, $candidates)) {
            if ($this->garudaBudgetExhausted($state)) {
                return new ReferenceVerification(
                    $referenceValue,
                    $doiLookup,
                    [],
                    transientFailure: true,
                    pendingReason: MatchReasonBuilder::GARUDA_BUDGET,
                );
            }

            $state->garudaLookups++;

            try {
                $candidates = $this->merge($candidates, $this->garuda->search($searchQuery));
                $state->garudaResponded = true;
            } catch (GarudaUnavailableException $exception) {
                if (! $state->garudaResponded) {
                    throw $exception;
                }

                Log::warning('Garuda lookup failed; reference deferred.', [
                    'reference_id' => $reference->getKey(),
                    'exception' => $exception,
                ]);

                return new ReferenceVerification(
                    $referenceValue,
                    $doiLookup,
                    [],
                    transientFailure: true,
                    pendingReason: MatchReasonBuilder::GARUDA_TRANSIENT,
                );
            }
        }

        return new ReferenceVerification($referenceValue, $doiLookup, $candidates);
    }

    /**
     * Whether the fallback source is worth consulting: it is enabled, there is
     * enough title signal to search, and no primary candidate is a plausible
     * title match.
     *
     * @param  list<WorkMetadata>  $candidates
     */
    private function needsFallback(ScoringReference $reference, array $candidates): bool
    {
        if (! (bool) config('services.garuda.enabled', true)) {
            return false;
        }

        $title = trim((string) $reference->title);

        if (mb_strlen($title) < 8) {
            return false;
        }

        if ($candidates === []) {
            return true;
        }

        $threshold = $this->scoring->fallbackTitleThreshold();

        foreach ($candidates as $candidate) {
            if (($this->strings->levenshtein($title, $candidate->title) ?? 0.0) >= $threshold) {
                return false;
            }
        }

        return true;
    }

    private function garudaBudgetExhausted(VerificationRunState $state): bool
    {
        $budget = (int) config('services.garuda.max_requests_per_document', 500);

        return $budget > 0 && $state->garudaLookups >= $budget;
    }

    /**
     * @return list<BibliographicSource>
     */
    private function primarySources(): array
    {
        $sources = [];

        foreach ((array) config('analysis.metadata.primary_sources', [CrossrefClient::class]) as $binding) {
            $source = app($binding);

            if (! $source instanceof BibliographicSource) {
                throw new LogicException(sprintf('Metadata source [%s] must implement %s.', is_string($binding) ? $binding : get_debug_type($binding), BibliographicSource::class));
            }

            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * Merge candidates, de-duplicating by normalized DOI first (keeping the
     * DOI-lookup copy, which is authoritative) and by normalized title for
     * DOI-less works.
     *
     * @param  list<WorkMetadata>  $existing
     * @param  list<WorkMetadata>  $incoming
     * @return list<WorkMetadata>
     */
    private function merge(array $existing, array $incoming): array
    {
        $merged = [];
        $seenDois = [];
        $seenTitles = [];

        foreach (array_merge($existing, $incoming) as $work) {
            if ($work->doi !== null) {
                if (isset($seenDois[$work->doi])) {
                    continue;
                }

                $seenDois[$work->doi] = true;
                $merged[] = $work;

                continue;
            }

            $title = $this->titleKey($work->title);

            if ($title !== null && isset($seenTitles[$title])) {
                continue;
            }

            if ($title !== null) {
                $seenTitles[$title] = true;
            }

            $merged[] = $work;
        }

        return $merged;
    }

    private function titleKey(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $title = mb_strtolower(trim($title));
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return $title === '' ? null : $title;
    }
}
