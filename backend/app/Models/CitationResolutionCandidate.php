<?php

namespace App\Models;

use App\Enums\CitationResolutionMethod;
use Database\Factories\CitationResolutionCandidateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ranked alternative reference for a citation that could not be committed
 * (Phase 05.1 W2, `citation_resolution_candidates`).
 *
 * Mirrors {@see ReferenceFindingCandidate}: the citation's accepted pairing is
 * `researched_document_citations.researched_document_reference_id`; candidates
 * are the suggestions shown to the reviewer.
 */
class CitationResolutionCandidate extends Model
{
    /** @use HasFactory<CitationResolutionCandidateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'citation_id',
        'researched_document_reference_id',
        'rank',
        'confidence',
        'method',
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
            'method' => CitationResolutionMethod::class,
        ];
    }

    /**
     * The citation this candidate belongs to.
     *
     * @return BelongsTo<ResearchedDocumentCitation, $this>
     */
    public function citation(): BelongsTo
    {
        return $this->belongsTo(ResearchedDocumentCitation::class, 'citation_id');
    }

    /**
     * The suggested bibliography reference.
     *
     * @return BelongsTo<ResearchedDocumentReference, $this>
     */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(ResearchedDocumentReference::class, 'researched_document_reference_id');
    }
}
