<?php

namespace App\Services\ReferenceFinding;

use App\Enums\DocumentStatus;
use App\Enums\ReferenceFindingStatus;
use App\Exceptions\StateConflictException;
use App\Models\ReferenceFinding;
use App\Models\ResearchedDocumentReference;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Manual review of a reference finding
 * (`PATCH /references/{reference}/finding`, `docs/API_SPEC.md` §5).
 *
 * This is the only manual writer of `reference_findings`: the automated pipeline
 * uses {@see ReferenceFindingWriter}. A review is
 * refused with `409` while the document is `processing`, so a running pipeline
 * can never be overwritten (OQ-17/D-05-05). A reference without a finding gets a
 * manual finding with no confidence (no evidence to record, D-05-06).
 */
final class ReferenceFindingReviewService
{
    public function review(
        ResearchedDocumentReference $reference,
        ReferenceFindingStatus $status,
        ?string $candidateId,
        ?string $reason,
        User $reviewer,
    ): ReferenceFinding {
        if ($reference->researchedDocument()->where('status', DocumentStatus::Processing->value)->exists()) {
            throw StateConflictException::findingReviewNotAllowed();
        }

        /** @var ReferenceFinding $finding */
        $finding = ReferenceFinding::query()->firstOrNew([
            'researched_document_reference_id' => $reference->getKey(),
        ]);

        if ($candidateId !== null && (! $finding->exists || ! $finding->candidates()->whereKey($candidateId)->exists())) {
            throw ValidationException::withMessages([
                'selected_candidate_id' => ['Kandidat yang dipilih tidak valid untuk temuan ini.'],
            ]);
        }

        $finding->fill([
            'researched_document_id' => $reference->researched_document_id,
            'status' => $status,
            'selected_candidate_id' => $candidateId,
            'reason' => $reason,
            'is_manual' => true,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
        ]);

        // `confidence` is deliberately untouched: it records the automated
        // evidence, not the manual decision (D-05-06).
        $finding->save();

        return $finding->refresh()->load('candidates');
    }
}
