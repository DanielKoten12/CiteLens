<?php

namespace App\Models;

use Database\Factories\ResearchedDocumentCitationLocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A highlight location (page + PDF bounding box) of an in-text citation.
 *
 * Maps the canonical `researched_document_citation_locations` table; the schema
 * column for the parent is `citation_id`.
 */
class ResearchedDocumentCitationLocation extends Model
{
    /** @use HasFactory<ResearchedDocumentCitationLocationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'citation_id',
        'page_number',
        'x',
        'y',
        'width',
        'height',
        'page_width',
        'page_height',
        'coordinate_system',
        'location_index',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'location_index' => 'integer',
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
            'page_width' => 'float',
            'page_height' => 'float',
        ];
    }

    /**
     * The citation this location belongs to.
     *
     * @return BelongsTo<ResearchedDocumentCitation, $this>
     */
    public function citation(): BelongsTo
    {
        return $this->belongsTo(ResearchedDocumentCitation::class, 'citation_id');
    }
}
