<?php

namespace App\Models;

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use Database\Factories\ResearchedDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

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
            'status' => DocumentStatus::class,
            'analysis_step' => AnalysisStep::class,
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
     * The single uploaded file attached to this document.
     *
     * @return MorphOne<File, $this>
     */
    public function file(): MorphOne
    {
        return $this->morphOne(File::class, 'fileable');
    }

    /**
     * Bibliography entries extracted from this document.
     *
     * @return HasMany<ResearchedDocumentReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(ResearchedDocumentReference::class);
    }

    /**
     * In-text citation occurrences extracted from this document.
     *
     * @return HasMany<ResearchedDocumentCitation, $this>
     */
    public function citations(): HasMany
    {
        return $this->hasMany(ResearchedDocumentCitation::class);
    }

    /**
     * Reference verification verdicts of this document.
     *
     * @return HasMany<ReferenceFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(ReferenceFinding::class);
    }

    /**
     * Generated reports of this document.
     *
     * @return HasMany<GeneratedDocumentReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(GeneratedDocumentReport::class);
    }

    /**
     * Scope to documents owned by the given user.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
