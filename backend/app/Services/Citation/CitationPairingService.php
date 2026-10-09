<?php

namespace App\Services\Citation;

use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use Illuminate\Validation\ValidationException;

/**
 * Manual pairing of an in-text citation
 * (`PATCH /citations/{citation}`, `docs/API_SPEC.md` §6).
 *
 * The same-document rule is enforced here, after ownership has been resolved by
 * `OwnedResourceFinder`, so a foreign reference id and a non-existent one are
 * indistinguishable (`422`, no existence disclosure — D-05-10).
 */
final class CitationPairingService
{
    /**
     * Pair the citation with a reference of the same document, or clear the
     * pairing when `$referenceId` is `null`.
     */
    public function pair(ResearchedDocumentCitation $citation, ?string $referenceId): ResearchedDocumentCitation
    {
        if ($referenceId !== null && ! $this->referenceExistsInDocument($citation->researched_document_id, $referenceId)) {
            throw ValidationException::withMessages([
                'researched_document_reference_id' => ['The selected reference is invalid for this document.'],
            ]);
        }

        $citation->update(['researched_document_reference_id' => $referenceId]);

        return $citation;
    }

    /**
     * Reject a list-filter reference id that is not of this document.
     */
    public function assertReferenceBelongsToDocument(ResearchedDocument $document, ?string $referenceId): void
    {
        if ($referenceId === null) {
            return;
        }

        if (! $this->referenceExistsInDocument($document->getKey(), $referenceId)) {
            throw ValidationException::withMessages([
                'reference_id' => ['The selected reference is invalid for this document.'],
            ]);
        }
    }

    private function referenceExistsInDocument(?string $documentId, string $referenceId): bool
    {
        if ($documentId === null) {
            return false;
        }

        return ResearchedDocumentReference::query()
            ->whereKey($referenceId)
            ->where('researched_document_id', $documentId)
            ->exists();
    }
}
