<?php

namespace App\Models;

use Database\Factories\ResearchedDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An uploaded research document and its analysis lifecycle.
 *
 * Maps the canonical `researched_documents` table (`docs/DB_SCHEMA.md`).
 */
class ResearchedDocument extends Model
{
    /** @use HasFactory<ResearchedDocumentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'name',
        'status',
        'analysis_progress',
        'analysis_step',
        'analysis_error',
        'analysis_started_at',
        'analysis_completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'analysis_progress' => 'integer',
            'analysis_started_at' => 'datetime',
            'analysis_completed_at' => 'datetime',
        ];
    }

    /**
     * The user who owns this document.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The polymorphic file records attached to this document.
     *
     * @return MorphMany<File, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }
}
