<?php

namespace App\Services\Document;

use App\Models\ResearchedDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hard-deletes documents and their entire owned history (`docs/API_SPEC.md` §4/§2.8).
 *
 * The database cascades references, locations, citations, findings, candidates and
 * reports. The polymorphic `files` rows are not covered by a foreign key, so they
 * are collected and deleted in the same transaction and their stored objects are
 * removed after the commit.
 */
final class DocumentDeletionService
{
    public function __construct(
        private readonly DocumentFileManager $fileManager,
    ) {}

    /**
     * Delete one document and everything that belongs to it.
     */
    public function delete(ResearchedDocument $document): void
    {
        $objects = DB::transaction(function () use ($document): array {
            $objects = $this->fileManager->detachForDocument($document);

            $document->delete();

            return $objects;
        });

        $this->fileManager->deleteStoredObjects($objects);
    }

    /**
     * Delete the authenticated user's entire history, chunked so the whole set is
     * never loaded into memory.
     *
     * @return int the number of deleted documents
     */
    public function purge(User $user): int
    {
        $deleted = 0;

        $user->researchedDocuments()
            ->lazyById()
            ->each(function (ResearchedDocument $document) use (&$deleted): void {
                $this->delete($document);
                $deleted++;
            });

        return $deleted;
    }
}
