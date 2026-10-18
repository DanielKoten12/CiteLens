<?php

namespace App\Data\Location;

use App\Data\BaseData;
use App\Models\ResearchedDocumentCitationLocation;
use App\Models\ResearchedDocumentReferenceLocation;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A highlight location (1-based page + PDF bounding box) shared by reference
 * detail, citation detail and the findings feed (D-05-08).
 *
 * The coordinate system is `pdf_points_top_left` and is stored, never
 * re-derived; the viewer relies on these values verbatim.
 */
#[MapName(SnakeCaseMapper::class)]
final class LocationPreviewData extends BaseData
{
    public function __construct(
        public int $pageNumber,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?float $pageWidth,
        public ?float $pageHeight,
        public string $coordinateSystem,
        public int $locationIndex,
    ) {}

    public static function fromModel(ResearchedDocumentReferenceLocation|ResearchedDocumentCitationLocation $location): self
    {
        return new self(
            pageNumber: $location->page_number,
            x: $location->x,
            y: $location->y,
            width: $location->width,
            height: $location->height,
            pageWidth: $location->page_width,
            pageHeight: $location->page_height,
            coordinateSystem: $location->coordinate_system,
            locationIndex: $location->location_index,
        );
    }
}
