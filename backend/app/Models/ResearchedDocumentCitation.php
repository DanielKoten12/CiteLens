<?php

namespace App\Models;

use App\Models\Concerns\ScopesThroughDocument;
use Database\Factories\ResearchedDocumentCitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One in-text citation occurrence.
 *
 * Maps the canonical `researched_document_citations` table. Citation status is
 * derived, never stored (`docs/DB_SCHEMA.md`, `docs/API_SPEC.md` §2.6).
 */
class ResearchedDocumentCitation extends Model
{
    /** @use HasFactory<ResearchedDocumentCitationFactory> */
    use HasFactory, HasUuids, ScopesThroughDocument;

    protected $fillable = [
        'researched_document_id',
        'researched_document_reference_id',
        'citation_text',
        'citation_marker',
        'context_before',
        'context_after',
        'text_start_offset',
        'text_end_offset',
        'occurrence_index',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrence_index' => 'integer',
            'text_start_offset' => 'integer',
            'text_end_offset' => 'integer',
        ];
    }

    /**
     * The paired reference, or null for an unresolved (hallucination) citation.
     *
     * @return BelongsTo<ResearchedDocumentReference, $this>
     */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(ResearchedDocumentReference::class, 'researched_document_reference_id');
    }

    /**
     * Highlight locations of this citation, in document order.
     *
     * @return HasMany<ResearchedDocumentCitationLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(ResearchedDocumentCitationLocation::class, 'citation_id')->orderBy('location_index');
    }
}
