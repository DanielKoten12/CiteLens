<?php

namespace App\Services;

use App\Exceptions\DocumentUploadFailedException;
use App\Models\ResearchedDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persists an uploaded document against the canonical domain schema.
 *
 * Responsibilities:
 *   1. create the `researched_documents` row (status `pending`, step `queued`),
 *   2. store the PDF on the configured disk,
 *   3. attach the polymorphic `files` row.
 *
 * The operation is transactional: a failed database write removes the stored
 * file, and a failed file write rolls back the database changes.
 */
class DocumentUploadService
{
    public function handle(User $user, UploadedFile $file, ?string $name = null): ResearchedDocument
    {
        $documentId = (string) Str::uuid();
        $storedPath = null;

        try {
            return DB::transaction(function () use ($user, $file, $name, $documentId, &$storedPath): ResearchedDocument {
                $document = $this->createDocument(
                    $user,
                    $name ?? $file->getClientOriginalName(),
                    $documentId,
                );

                $storedPath = $file->storeAs(
                    "documents/{$documentId}",
                    $file->hashName(),
                    $this->diskName(),
                );

                if ($storedPath === false) {
                    throw DocumentUploadFailedException::storageFailed();
                }

                $document->files()->create([
                    'filename' => $file->getClientOriginalName(),
                    'path' => $storedPath,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);

                return $document;
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk($this->diskName())->delete($storedPath);
            }

            if ($exception instanceof DocumentUploadFailedException) {
                throw $exception;
            }

            throw DocumentUploadFailedException::persistenceFailed($exception);
        }
    }

    /**
     * Create the research document with a deterministic id so the stored file
     * directory and the database row share the same identifier.
     */
    private function createDocument(User $user, string $name, string $documentId): ResearchedDocument
    {
        $document = new ResearchedDocument([
            'user_id' => $user->id,
            'name' => $name,
            'status' => 'pending',
            'analysis_progress' => 0,
            'analysis_step' => 'queued',
        ]);

        $document->id = $documentId;
        $document->save();

        return $document;
    }

    /**
     * The filesystem disk used for stored documents.
     */
    private function diskName(): string
    {
        return (string) config('filesystems.default');
    }
}
