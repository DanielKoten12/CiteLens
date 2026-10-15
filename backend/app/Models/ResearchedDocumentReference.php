<?php

namespace App\Models;

use App\Models\Concerns\ScopesThroughDocument;
use Database\Factories\ResearchedDocumentReferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A bibliography entry extracted from a research document.
 *
 * Maps the canonical `researched_document_references` table (`docs/DB_SCHEMA.md`).
 */
class ResearchedDocumentReference extends Model
{
    /** @use HasFactory<ResearchedDocumentReferenceFactory> */
    use HasFactory, HasUuids, ScopesThroughDocument;

    protected $fillable = [
        'researched_document_id',
        'raw_text',
        'doi',
        'title',
        'authors',
        'publication_name',
        'publication_year',
        'text_start_offset',
        'text_end_offset',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'publication_year' => 'integer',
            'text_start_offset' => 'integer',
            'text_end_offset' => 'integer',
        ];
    }

    /**
     * Highlight locations of this reference, in document order.
     *
     * @return HasMany<ResearchedDocumentReferenceLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(ResearchedDocumentReferenceLocation::class)->orderBy('location_index');
    }

    /**
     * The verification verdict for this reference (one per reference).
     *
     * @return HasOne<ReferenceFinding, $this>
     */
    public function finding(): HasOne
    {
        return $this->hasOne(ReferenceFinding::class);
    }

    /**
     * In-text citations resolved to this reference, in document order.
     *
     * @return HasMany<ResearchedDocumentCitation, $this>
     */
    public function citations(): HasMany
    {
        return $this->hasMany(ResearchedDocumentCitation::class, 'researched_document_reference_id')
            ->orderByRaw('occurrence_index IS NULL')
            ->orderBy('occurrence_index')
            ->orderBy('id');
    }
}
