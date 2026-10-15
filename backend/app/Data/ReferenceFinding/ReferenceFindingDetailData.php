<?php

namespace App\Data\ReferenceFinding;

use App\Data\BaseData;
use App\Enums\ReferenceFindingStatus;
use App\Models\ReferenceFinding;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The full finding of a reference detail response (`docs/API_SPEC.md` §5),
 * including the manual-review audit fields and the ranked candidates.
 */
#[MapName(SnakeCaseMapper::class)]
final class ReferenceFindingDetailData extends BaseData
{
    /**
     * @param  list<ReferenceFindingCandidatePreviewData>  $candidates
     */
    public function __construct(
        public string $id,
        public ReferenceFindingStatus $status,
        public ?float $confidence,
        public ?string $reason,
        public ?string $selectedCandidateId,
        public bool $isManual,
        public ?string $reviewedBy,
        public ?CarbonImmutable $reviewedAt,
        #[DataCollectionOf(ReferenceFindingCandidatePreviewData::class)]
        public array $candidates = [],
    ) {}

    public static function fromModel(ReferenceFinding $finding): self
    {
        $candidates = $finding->relationLoaded('candidates')
            ? $finding->candidates
            : $finding->candidates()->get();

        return new self(
            id: $finding->getKey(),
            status: $finding->status,
            confidence: $finding->confidence,
            reason: $finding->reason,
            selectedCandidateId: $finding->selected_candidate_id,
            isManual: $finding->is_manual,
            reviewedBy: $finding->reviewed_by,
            reviewedAt: $finding->reviewed_at?->toImmutable(),
            candidates: $candidates
                ->map(static fn ($candidate): ReferenceFindingCandidatePreviewData => ReferenceFindingCandidatePreviewData::fromModel($candidate))
                ->values()
                ->all(),
        );
    }
}
