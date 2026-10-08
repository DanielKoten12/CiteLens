<?php

namespace App\Services\Analysis\Steps;

use App\Enums\AnalysisStep;
use App\Exceptions\CrossrefUnavailableException;
use App\Models\ResearchedDocument;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\AnalysisProgress;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\Analysis\ReferenceVerification;
use App\Services\Analysis\ReferenceVerificationBatch;
use App\Services\Crossref\CrossrefClient;
use App\Services\Crossref\CrossrefWorkData;
use App\Services\Crossref\DoiLookup;
use App\Services\Crossref\DoiNormalizer;
use App\Services\Crossref\ReferenceQuery;
use App\Services\Scoring\ScoringReference;

/**
 * `crossref_validation` — retrieves Crossref candidates for every reference.
 *
 * Performs network I/O only: durable rows are written by `ScoreReferencesStep`
 * after scoring (D-04-05). References are processed in bibliography order
 * (`text_start_offset` ASC, nulls last, `id` tiebreak — OQ-18).
 *
 * Outage policy (D-04-01 / OQ-03): a completed Crossref exchange (a lookup hit
 * or a `404` miss) proves reachability. A `CrossrefUnavailableException` before
 * any completed exchange is rethrown and fails the document; after one, only
 * that reference degrades to `pending` and the rest continue.
 */
final class ValidateReferencesStep implements PipelineStep
{
    public function __construct(
        private readonly CrossrefClient $client,
        private readonly DoiNormalizer $normalizer,
        private readonly AnalysisProgress $progress,
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

        $receivedAnyResponse = false;
        $verifications = [];
        $total = $references->count();

        foreach ($references as $index => $reference) {
            $referenceValue = ScoringReference::fromModel($reference, $this->normalizer);
            $doiLookup = DoiLookup::NotPresent;
            $candidates = [];

            if ($referenceValue->doi !== null) {
                if (! $referenceValue->doiValid) {
                    $doiLookup = DoiLookup::Malformed;
                } else {
                    try {
                        $work = $this->client->findByDoi($referenceValue->doi);
                        $receivedAnyResponse = true;

                        if ($work === null) {
                            $doiLookup = DoiLookup::NotFound;
                        } else {
                            $doiLookup = DoiLookup::Resolved;
                            $candidates[] = $work;
                        }
                    } catch (CrossrefUnavailableException $exception) {
                        if (! $receivedAnyResponse) {
                            throw $exception;
                        }

                        $verifications[] = new ReferenceVerification($referenceValue, DoiLookup::NotPresent, [], transientFailure: true);
                        $this->progress->report($document, $this->step(), $index + 1, $total);

                        continue;
                    }
                }
            }

            // Best-effort bibliographic search whenever there is data to search on
            // (D-04-02): it supplies the correct-publication candidate for a
            // conflicting/non-resolving DOI and the candidates for a no-DOI entry.
            if ($reference->title !== null || $reference->authors !== null) {
                try {
                    $search = $this->client->searchBibliographic(ReferenceQuery::fromReference($reference));
                    $receivedAnyResponse = true;
                    $candidates = $this->merge($candidates, $search);
                } catch (CrossrefUnavailableException $exception) {
                    if (! $receivedAnyResponse) {
                        throw $exception;
                    }

                    $verifications[] = new ReferenceVerification($referenceValue, $doiLookup, [], transientFailure: true);
                    $this->progress->report($document, $this->step(), $index + 1, $total);

                    continue;
                }
            }

            $verifications[] = new ReferenceVerification($referenceValue, $doiLookup, $candidates);
            $this->progress->report($document, $this->step(), $index + 1, $total);
        }

        $context->setVerification(new ReferenceVerificationBatch($verifications));
    }

    /**
     * Merge DOI-lookup and search candidates, de-duplicating by normalized DOI
     * first (keeping the DOI-lookup copy, which is authoritative) and by
     * normalized title for DOI-less works.
     *
     * @param  list<CrossrefWorkData>  $existing
     * @param  list<CrossrefWorkData>  $incoming
     * @return list<CrossrefWorkData>
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
