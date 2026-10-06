<?php

namespace App\Services;

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use App\Exceptions\DocumentUploadFailedException;
use App\Exceptions\FileStorageException;
use App\Models\ResearchedDocument;
use App\Models\User;
use App\Services\Document\DocumentFileManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Persists an uploaded document against the canonical domain schema.
 *
 * Responsibilities:
 *   1. create the `researched_documents` row (status `pending`, step `queued`),
 *   2. store the PDF and its polymorphic `file` row through {@see DocumentFileManager}.
 *
 * The operation is transactional: a failed database write rolls back the document
 * and a failed file-row write removes the stored object. Failures are mapped to
 * the canonical upload error (storage vs. persistence).
 */
class DocumentUploadService
{
    public function __construct(
        private readonly DocumentFileManager $fileManager,
    ) {}

    public function handle(User $user, UploadedFile $file, ?string $name = null): ResearchedDocument
    {
        try {
            return DB::transaction(function () use ($user, $file, $name): ResearchedDocument {
                $document = $this->createDocument(
                    $user,
                    $name ?? $file->getClientOriginalName(),
                );

                $this->fileManager->store($document, $file, $file->getClientOriginalName());

                return $document;
            });
        } catch (FileStorageException $exception) {
            throw DocumentUploadFailedException::storageFailed();
        } catch (DocumentUploadFailedException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DocumentUploadFailedException::persistenceFailed($exception);
        }
    }

    private function createDocument(User $user, string $name): ResearchedDocument
    {
        $document = new ResearchedDocument([
            'user_id' => $user->id,
            'name' => $name,
            'status' => DocumentStatus::Pending,
            'analysis_progress' => 0,
            'analysis_step' => AnalysisStep::Queued,
        ]);

        $document->save();

        return $document;
    }
}
