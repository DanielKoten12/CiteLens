<?php

namespace App\Services\Document;

use App\Models\CitationResolutionCandidate;
use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentCitationLocation;
use App\Models\ResearchedDocumentReference;
use App\Models\ResearchedDocumentReferenceLocation;

/**
 * Removes the derived rows produced by a previous analysis run so a new run can
 * start from a clean slate.
 *
 * The order is explicit and FK-safe (child rows before parents) rather than
 * relying on database cascade, so a future relation that does not cascade cannot
 * leave orphan rows. The uploaded document and its `files` row are never touched.
 *
 * Callers own the surrounding transaction so a crash cannot leave the document
 * half-reset. The method is idempotent.
 */
final class DocumentAnalysisResetService
{
    /**
     * Delete every reference/citation/location/finding/candidate of the document.
     */
    public function reset(ResearchedDocument $document): void
    {
        $citationIds = ResearchedDocumentCitation::query()
            ->where('researched_document_id', $document->getKey())
            ->pluck('id');

        if ($citationIds->isNotEmpty()) {
            CitationResolutionCandidate::query()
                ->whereIn('citation_id', $citationIds)
                ->delete();

            ResearchedDocumentCitationLocation::query()
                ->whereIn('citation_id', $citationIds)
                ->delete();
        }

        ResearchedDocumentCitation::query()
            ->where('researched_document_id', $document->getKey())
            ->delete();

        $referenceIds = ResearchedDocumentReference::query()
            ->where('researched_document_id', $document->getKey())
            ->pluck('id');

        if ($referenceIds->isNotEmpty()) {
            ResearchedDocumentReferenceLocation::query()
                ->whereIn('researched_document_reference_id', $referenceIds)
                ->delete();
        }

        $findingIds = ReferenceFinding::query()
            ->where('researched_document_id', $document->getKey())
            ->pluck('id');

        if ($findingIds->isNotEmpty()) {
            ReferenceFindingCandidate::query()
                ->whereIn('reference_finding_id', $findingIds)
                ->delete();
        }

        ReferenceFinding::query()
            ->where('researched_document_id', $document->getKey())
            ->delete();

        ResearchedDocumentReference::query()
            ->where('researched_document_id', $document->getKey())
            ->delete();
    }
}
