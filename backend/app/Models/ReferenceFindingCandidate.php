<?php

namespace App\Models;

use Database\Factories\ReferenceFindingCandidateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ranked possible external match for a reference finding.
 *
 * Maps the canonical `reference_finding_candidates` table. Ranking is unique per
 * finding (`rank` 1..N, best first).
 */
class ReferenceFindingCandidate extends Model
{
    /** @use HasFactory<ReferenceFindingCandidateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'reference_finding_id',
        'rank',
        'confidence',
        'doi',
        'title',
        'authors',
        'publication_name',
        'publication_year',
        'url',
        'match_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'confidence' => 'float',
            'publication_year' => 'integer',
        ];
    }

    /**
     * The finding this candidate belongs to.
     *
     * @return BelongsTo<ReferenceFinding, $this>
     */
    public function referenceFinding(): BelongsTo
    {
        return $this->belongsTo(ReferenceFinding::class, 'reference_finding_id');
    }
}
