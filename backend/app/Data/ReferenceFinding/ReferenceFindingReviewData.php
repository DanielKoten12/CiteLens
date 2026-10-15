<?php

namespace App\Data\ReferenceFinding;

use App\Data\BaseData;
use App\Enums\ReferenceFindingStatus;
use App\Models\ReferenceFinding;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Response of `PATCH /references/{reference}/finding`
 * (`docs/API_SPEC.md` §5): the reviewed finding plus the audit fields.
 */
#[MapName(SnakeCaseMapper::class)]
final class ReferenceFindingReviewData extends BaseData
{
    public function __construct(
        public string $id,
        public ReferenceFindingStatus $status,
        public ?float $confidence,
        public ?string $reason,
        public ?string $selectedCandidateId,
        public bool $isManual,
        public ?string $reviewedBy,
        public ?CarbonImmutable $reviewedAt,
        public ?CarbonImmutable $updatedAt,
    ) {}

    public static function fromModel(ReferenceFinding $finding): self
    {
        return new self(
            id: $finding->getKey(),
            status: $finding->status,
            confidence: $finding->confidence,
            reason: $finding->reason,
            selectedCandidateId: $finding->selected_candidate_id,
            isManual: $finding->is_manual,
            reviewedBy: $finding->reviewed_by,
            reviewedAt: $finding->reviewed_at?->toImmutable(),
            updatedAt: $finding->updated_at?->toImmutable(),
        );
    }
}
