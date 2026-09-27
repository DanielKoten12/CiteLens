<?php

namespace App\Http\Resources;

use App\Models\File;
use App\Models\ResearchedDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Serializes a {@see ResearchedDocument} for the public API.
 *
 * @mixin ResearchedDocument
 */
class ResearchedDocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'progress' => $this->analysis_progress,
            'current_step' => $this->analysis_step,
            'error' => $this->analysis_error,
            'file' => $this->filePayload(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * The uploaded file attached to the document, if any.
     *
     * @return array<string, mixed>|null
     */
    private function filePayload(): ?array
    {
        /** @var File|null $file */
        $file = $this->whenLoaded('files', fn (): ?File => $this->files->first());

        if ($file === null) {
            return null;
        }

        return [
            'filename' => $file->filename,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'url' => $this->fileUrl($file),
        ];
    }

    /**
     * Resolve a (signed) URL for a stored file.
     *
     * Documents are stored on a private disk, so a temporary signed URL is
     * preferred over a public one.
     */
    private function fileUrl(File $file): ?string
    {
        $disk = Storage::disk((string) config('filesystems.default'));

        try {
            return $disk->providesTemporaryUrls()
                ? $disk->temporaryUrl($file->path, now()->addHour())
                : $disk->url($file->path);
        } catch (Throwable) {
            return null;
        }
    }
}
