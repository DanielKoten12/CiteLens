<?php

namespace App\Data\Citation;

use App\Data\BaseData;
use App\Models\ResearchedDocumentReference;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Minimal preview of the reference a citation is paired with
 * (`docs/API_SPEC.md` §6).
 */
#[MapName(SnakeCaseMapper::class)]
final class CitationReferencePreviewData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $title,
    ) {}

    public static function fromModel(ResearchedDocumentReference $reference): self
    {
        return new self(
            id: $reference->getKey(),
            title: $reference->title,
        );
    }
}
