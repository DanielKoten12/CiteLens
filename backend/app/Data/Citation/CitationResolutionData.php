<?php

namespace App\Data\Citation;

use App\Data\BaseData;
use App\Enums\CitationResolutionMethod;
use App\Enums\CitationResolutionState;
use App\Models\ResearchedDocumentCitation;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Resolution provenance of one citation (Phase 05.1 W2).
 *
 * `state` drives the derived status; `method`/`confidence`/`hint_index` explain
 * how the pairing was produced and are read-only for the client.
 */
#[MapName(SnakeCaseMapper::class)]
final class CitationResolutionData extends BaseData
{
    public function __construct(
        public CitationResolutionState $state,
        public ?CitationResolutionMethod $method,
        public ?float $confidence,
        public ?int $hintIndex,
    ) {}

    public static function fromModel(ResearchedDocumentCitation $citation): self
    {
        return new self(
            // The DB default is `unmatched`; a model instance created without a
            // refresh may not carry it yet.
            state: $citation->resolution_state ?? CitationResolutionState::Unmatched,
            method: $citation->resolution_method,
            confidence: $citation->resolution_confidence,
            hintIndex: $citation->extraction_reference_index,
        );
    }
}
