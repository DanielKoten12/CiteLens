<?php

namespace App\Services\ReferenceFinding;

use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use App\Models\ResearchedDocument;
use App\Services\Scoring\ReferenceVerdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single writer of automated `reference_findings` and
 * `reference_finding_candidates` rows.
 *
 * `ScoreReferencesStep` calls this once per run so the whole document is
 * persisted in one transaction. The writer enforces the canonical invariants:
 * one finding per reference, `rank = 1..N` unique per finding, and
 * `selected_candidate_id` belonging to the finding. The circular FK
 * (`reference_findings.selected_candidate_id` → `reference_finding_candidates.id`)
 * forces nulling the pointer before candidates are replaced.
 */
final class ReferenceFindingWriter
{
    /**
     * @param  list<ReferenceVerdict>  $verdicts
     */
    public function persistBatch(ResearchedDocument $document, array $verdicts): void
    {
        DB::transaction(function () use ($document, $verdicts): void {
            foreach ($verdicts as $verdict) {
                $this->persist($document, $verdict);
            }
        });
    }

    private function persist(ResearchedDocument $document, ReferenceVerdict $verdict): void
    {
        /** @var ReferenceFinding $finding */
        $finding = ReferenceFinding::query()->firstOrNew([
            'researched_document_reference_id' => $verdict->referenceId,
        ]);

        // Circular FK: the finding may point at a candidate we are about to delete.
        $finding->selected_candidate_id = null;

        $finding->fill([
            'researched_document_id' => $document->getKey(),
            'status' => $verdict->status,
            'confidence' => $verdict->confidence,
            'reason' => $verdict->reason,
        ]);

        // Manual audit fields are deliberately untouched by automated runs.
        $finding->save();

        $finding->candidates()->delete();

        $idsByRank = [];

        foreach ($verdict->candidates as $candidate) {
            $id = (string) Str::uuid();

            // `forceCreate` is required: `id` is not mass-assignable, and the
            // generated id must match the one later stored in `selected_candidate_id`.
            ReferenceFindingCandidate::query()->forceCreate([
                'id' => $id,
                'reference_finding_id' => $finding->getKey(),
                'rank' => $candidate->rank,
                'confidence' => round(max(0.0, min(1.0, $candidate->confidence())), 4),
                'doi' => $candidate->work->doi,
                'title' => $candidate->work->title,
                'authors' => $candidate->work->authorString(),
                'publication_name' => $candidate->work->containerTitle,
                'publication_year' => $candidate->work->publicationYear,
                'url' => $candidate->work->url,
                'match_reason' => $candidate->matchReason,
            ]);

            $idsByRank[$candidate->rank] = $id;
        }

        $finding->selected_candidate_id = $verdict->selectedRank === null
            ? null
            : ($idsByRank[$verdict->selectedRank] ?? null);
        $finding->save();
    }
}
