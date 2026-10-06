<?php

namespace App\Models;

use Database\Factories\ResearchedDocumentReferenceLocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A highlight location (page + PDF bounding box) of a bibliography reference.
 *
 * Maps the canonical `researched_document_reference_locations` table.
 */
class ResearchedDocumentReferenceLocation extends Model
{
    /** @use HasFactory<ResearchedDocumentReferenceLocationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'researched_document_reference_id',
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
     * The reference this location belongs to.
     *
     * @return BelongsTo<ResearchedDocumentReference, $this>
     */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(ResearchedDocumentReference::class, 'researched_document_reference_id');
    }
}
