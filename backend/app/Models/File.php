<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A stored file attached to a polymorphic owner (e.g. a research document or
 * a generated report).
 *
 * Maps the canonical `files` table (`docs/DB_SCHEMA.md`).
 */
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'fileable_type',
        'fileable_id',
        'filename',
        'path',
        'disk',
        'mime_type',
        'size',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * The owning model (research document, generated report, ...).
     *
     * @return MorphTo<Model, $this>
     */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }
}
