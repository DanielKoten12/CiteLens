<?php

namespace App\Data\ReferenceFinding;

use App\Data\BaseData;
use App\Enums\ReferenceFindingStatus;
use App\Models\ReferenceFinding;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The embedded finding preview of a reference list item
 * (`docs/API_SPEC.md` §5).
 */
#[MapName(SnakeCaseMapper::class)]
final class ReferenceFindingPreviewData extends BaseData
{
    public function __construct(
        public string $id,
        public ReferenceFindingStatus $status,
        public ?float $confidence,
        public ?string $reason,
        public ?string $selectedCandidateId,
    ) {}

    public static function fromModel(ReferenceFinding $finding): self
    {
        return new self(
            id: $finding->getKey(),
            status: $finding->status,
            confidence: $finding->confidence,
            reason: $finding->reason,
            selectedCandidateId: $finding->selected_candidate_id,
        );
    }
}
