<?php

namespace App\Services\Ownership;

use App\Exceptions\ResourceNotFoundException;
use App\Models\GeneratedDocumentReport;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use App\Models\User;

/**
 * Resolves document-owned resources for the authenticated user.
 *
 * This is the only allowed resolution path for document-owned resources: it scopes
 * every lookup through the parent document and throws the per-resource
 * {@see ResourceNotFoundException} (404, no existence disclosure) for missing **and**
 * foreign resources alike (`docs/SECURITY.md` §2.1).
 *
 * `find()` is used deliberately instead of `findOrFail()`: the framework converts
 * `ModelNotFoundException` into a generic 404 message, which would lose the
 * per-resource message from the canonical API spec.
 */
final class OwnedResourceFinder
{
    public function document(User $user, string $id): ResearchedDocument
    {
        return $user->researchedDocuments()->find($id)
            ?? throw ResourceNotFoundException::document();
    }

    public function reference(User $user, string $id): ResearchedDocumentReference
    {
        return ResearchedDocumentReference::query()->forUser($user)->find($id)
            ?? throw ResourceNotFoundException::reference();
    }

    public function citation(User $user, string $id): ResearchedDocumentCitation
    {
        return ResearchedDocumentCitation::query()->forUser($user)->find($id)
            ?? throw ResourceNotFoundException::citation();
    }

    public function report(User $user, string $id): GeneratedDocumentReport
    {
        return GeneratedDocumentReport::query()->forUser($user)->find($id)
            ?? throw ResourceNotFoundException::report();
    }
}
