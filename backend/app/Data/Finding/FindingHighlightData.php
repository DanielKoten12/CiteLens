<?php

namespace App\Data\Finding;

use App\Data\BaseData;
use App\Data\Location\LocationPreviewData;
use App\Enums\FindingSeverity;
use App\Enums\FindingType;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One derived item of the findings/highlights feed
 * (`GET /documents/{document}/findings`, `docs/API_SPEC.md` §7).
 *
 * The feed is read-only and recomputed per request; nothing here is persisted.
 * `id` follows the canonical `ref-finding:{findingId}` / `citation:{citationId}`
 * format, built in PHP so the union SQL stays portable.
 */
#[MapName(SnakeCaseMapper::class)]
final class FindingHighlightData extends BaseData
{
    /**
     * @param  list<LocationPreviewData>  $locations
     */
    public function __construct(
        public string $id,
        public FindingType $type,
        public FindingSeverity $severity,
        public ?string $message,
        public ?string $referenceId,
        public ?string $citationId,
        public ?string $text,
        public ?int $startOffset,
        public ?int $endOffset,
        #[DataCollectionOf(LocationPreviewData::class)]
        public array $locations = [],
    ) {}

    /**
     * Build a feed item from one normalized union row.
     *
     * @param  list<LocationPreviewData>  $locations
     */
    public static function fromFeedRow(object $row, array $locations): self
    {
        $isReference = $row->source === 'reference';
        $prefix = $isReference ? 'ref-finding:' : 'citation:';

        return new self(
            id: $prefix.$row->entity_id,
            type: FindingType::from($row->type),
            severity: FindingSeverity::from($row->severity),
            message: $row->message,
            referenceId: $row->reference_id,
            citationId: $row->citation_id,
            text: $row->text,
            startOffset: $row->start_offset === null ? null : (int) $row->start_offset,
            endOffset: $row->end_offset === null ? null : (int) $row->end_offset,
            locations: $locations,
        );
    }
}
