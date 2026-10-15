<?php

namespace App\Data\Inference;

use App\Data\BaseData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Full response body of `POST /v1/extract` wrapped in its `data` object
 * (`docs/API_SPEC.md` §9).
 *
 * A document may legitimately produce zero references and zero citations.
 */
#[MapName(SnakeCaseMapper::class)]
final class ExtractionResultData extends BaseData
{
    /**
     * @param  list<ExtractedReferenceData>  $references
     * @param  list<ExtractedCitationData>  $citations
     */
    public function __construct(
        #[DataCollectionOf(ExtractedReferenceData::class)]
        public array $references = [],
        #[DataCollectionOf(ExtractedCitationData::class)]
        public array $citations = [],
    ) {}

    /**
     * Whether the extraction found no bibliography entries and no citations.
     */
    public function isEmpty(): bool
    {
        return $this->references === [] && $this->citations === [];
    }
}
