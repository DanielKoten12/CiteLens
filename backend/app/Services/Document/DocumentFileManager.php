<?php

namespace App\Services\Document;

use App\Exceptions\FileStorageException;
use App\Models\File;
use App\Models\GeneratedDocumentReport;
use App\Models\ResearchedDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Owns the lifecycle of private `files` rows and their stored objects.
 *
 * `files` is polymorphic with **no** foreign key on `fileable_id`, so deleting an
 * owner never removes its `files` row or the object. This is the single place
 * that stores, collects and deletes them; Phase 06 reuses it for reports.
 */
final class DocumentFileManager
{
    /**
     * Store an uploaded file for a morph owner and create its `files` row.
     *
     * The object is written first; if the row cannot be created the object is
     * removed again, so a failure never leaves an orphan file.
     *
     * @throws FileStorageException when the disk write fails
     */
    public function store(Model $owner, UploadedFile $file, string $filename): File
    {
        $path = $file->storeAs(
            $this->directory($owner),
            $file->hashName(),
            $this->diskName(),
        );

        if ($path === false) {
            throw FileStorageException::diskWriteFailed();
        }

        try {
            return File::query()->create([
                'fileable_type' => $owner->getMorphClass(),
                'fileable_id' => $owner->getKey(),
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk($this->diskName())->delete($path);

            throw $exception;
        }
    }

    /**
     * Delete one file: the stored object (best-effort, logged) then its row.
     */
    public function delete(File $file): void
    {
        $this->deleteStoredObjects([$file->path]);

        $file->delete();
    }

    /**
     * Collect and delete every `files` row belonging to the document or its reports.
     *
     * Must be called inside the caller's deletion transaction. The returned paths
     * are deleted from storage only after the transaction commits.
     *
     * @return list<string>
     */
    public function detachForDocument(ResearchedDocument $document): array
    {
        $files = $this->filesForDocument($document);
        $paths = $files->pluck('path')->all();

        File::query()->whereIn('id', $files->pluck('id'))->delete();

        return $paths;
    }

    /**
     * Delete stored objects after a DB commit. Failures are logged, never fatal:
     * the rows are already gone, so an orphan object is an operational concern.
     *
     * @param  iterable<string>  $paths
     */
    public function deleteStoredObjects(iterable $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk($this->diskName())->delete($path);
            } catch (Throwable $exception) {
                Log::warning('Failed to delete a stored document file.', [
                    'path' => $path,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Every `files` row owned by the document or its reports (polymorphic and the
     * canonical `generated_document_reports.file_id` pointer), deduplicated.
     *
     * @return Collection<int, File>
     */
    private function filesForDocument(ResearchedDocument $document): Collection
    {
        $reportIds = $document->reports()->pluck('id');
        $reportFileIds = $document->reports()->whereNotNull('file_id')->pluck('file_id');

        $reportMorphType = (new GeneratedDocumentReport)->getMorphClass();

        return File::query()
            ->where(function ($query) use ($document, $reportIds, $reportMorphType): void {
                $query
                    ->where(function ($query) use ($document): void {
                        $query->where('fileable_type', $document->getMorphClass())
                            ->where('fileable_id', $document->getKey());
                    })
                    ->orWhere(function ($query) use ($reportIds, $reportMorphType): void {
                        $query->where('fileable_type', $reportMorphType)
                            ->whereIn('fileable_id', $reportIds);
                    });
            })
            ->orWhereIn('id', $reportFileIds->all())
            ->get();
    }

    private function directory(Model $owner): string
    {
        return 'documents/'.$owner->getKey();
    }

    private function diskName(): string
    {
        return (string) config('filesystems.default');
    }
}
