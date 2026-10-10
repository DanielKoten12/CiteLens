<?php

namespace App\Services\Citation;

use App\Enums\CitationResolutionState;
use App\Models\CitationResolutionCandidate;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Services\Citations\CitationMatchConfig;
use App\Services\Citations\CitationResolution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single writer of automated citation-resolution state and candidates
 * (Phase 05.1 W2).
 *
 * One transaction per document: reset every citation to `unmatched` (clearing
 * the previous run's pairing and provenance), delete the document's candidates,
 * then write the new state/method/confidence/hint and the ranked candidates.
 * Candidate inserts are chunked; citation updates are per row because each row
 * carries distinct provenance (confidence/hint), and a per-row update is the
 * only portable form (SQLite/MySQL/Postgres `upsert` would require every
 * NOT NULL column, defeating the purpose).
 *
 * The manual pairing path ({@see CitationPairingService}) is deliberately
 * separate.
 */
final class CitationResolutionWriter
{
    private const int INSERT_CHUNK = 500;

    public function __construct(
        private readonly CitationMatchConfig $config,
    ) {}

    /**
     * @param  array<string, CitationResolution>  $resolutions  keyed by citation id
     * @param  array<string, int|null>  $hintIndexes  keyed by citation id
     */
    public function persistBatch(ResearchedDocument $document, array $resolutions, array $hintIndexes = []): void
    {
        if ($resolutions === []) {
            return;
        }

        DB::transaction(function () use ($document, $resolutions, $hintIndexes): void {
            $this->reset($document);

            $timestamp = now();
            $candidateRows = [];

            foreach ($resolutions as $citationId => $resolution) {
                ResearchedDocumentCitation::query()
                    ->whereKey($citationId)
                    ->update([
                        'researched_document_reference_id' => $resolution->referenceId,
                        'resolution_state' => $resolution->state->value,
                        'resolution_method' => $resolution->method?->value,
                        'resolution_confidence' => $this->confidence($resolution->confidence),
                        'extraction_reference_index' => $hintIndexes[$citationId] ?? $resolution->hintIndex,
                        'updated_at' => $timestamp,
                    ]);

                foreach (array_slice($resolution->candidates, 0, $this->config->maxCandidates()) as $candidate) {
                    $candidateRows[] = [
                        'id' => (string) Str::orderedUuid(),
                        'citation_id' => $citationId,
                        'researched_document_reference_id' => $candidate->referenceId,
                        'rank' => $candidate->rank,
                        'confidence' => $this->confidence($candidate->confidence),
                        'method' => $candidate->method->value,
                        'match_reason' => $candidate->matchReason,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
            }

            foreach (array_chunk($candidateRows, self::INSERT_CHUNK) as $chunk) {
                CitationResolutionCandidate::query()->insert($chunk);
            }
        });
    }

    /**
     * Clear every pairing, provenance field and candidate of the document.
     */
    private function reset(ResearchedDocument $document): void
    {
        $citationIds = ResearchedDocumentCitation::query()
            ->where('researched_document_id', $document->getKey())
            ->pluck('id');

        if ($citationIds->isNotEmpty()) {
            CitationResolutionCandidate::query()->whereIn('citation_id', $citationIds)->delete();
        }

        ResearchedDocumentCitation::query()
            ->where('researched_document_id', $document->getKey())
            ->update([
                'researched_document_reference_id' => null,
                'resolution_state' => CitationResolutionState::Unmatched->value,
                'resolution_method' => null,
                'resolution_confidence' => null,
                'extraction_reference_index' => null,
            ]);
    }

    private function confidence(?float $confidence): ?float
    {
        if ($confidence === null) {
            return null;
        }

        return round(max(0.0, min(1.0, $confidence)), 4);
    }
}
