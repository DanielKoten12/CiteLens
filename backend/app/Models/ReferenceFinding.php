<?php

namespace App\Models;

use App\Enums\ReferenceFindingStatus;
use App\Models\Concerns\ScopesThroughDocument;
use Database\Factories\ReferenceFindingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The verification verdict for one bibliography reference (one per reference,
 * enforced by a unique index — OQ-14).
 *
 * Maps the canonical `reference_findings` table (`docs/DB_SCHEMA.md`).
 */
class ReferenceFinding extends Model
{
    /** @use HasFactory<ReferenceFindingFactory> */
    use HasFactory, HasUuids, ScopesThroughDocument;

    protected $fillable = [
        'researched_document_id',
        'researched_document_reference_id',
        'selected_candidate_id',
        'status',
        'confidence',
        'reason',
        'reviewed_by',
        'reviewed_at',
        'is_manual',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReferenceFindingStatus::class,
            'confidence' => 'float',
            'is_manual' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * The reference this finding verifies.
     *
     * @return BelongsTo<ResearchedDocumentReference, $this>
     */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(ResearchedDocumentReference::class, 'researched_document_reference_id');
    }

    /**
     * The candidate selected as the best match, if any.
     *
     * @return BelongsTo<ReferenceFindingCandidate, $this>
     */
    public function selectedCandidate(): BelongsTo
    {
        return $this->belongsTo(ReferenceFindingCandidate::class, 'selected_candidate_id');
    }

    /**
     * The user who reviewed this finding manually, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Ranked candidate matches, best first.
     *
     * @return HasMany<ReferenceFindingCandidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(ReferenceFindingCandidate::class)->orderBy('rank');
    }
}
