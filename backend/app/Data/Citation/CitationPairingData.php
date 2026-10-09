<?php

namespace App\Data\Citation;

use App\Data\BaseData;
use App\Enums\CitationStatus;
use App\Models\ResearchedDocumentCitation;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Response of `PATCH /citations/{citation}` (`docs/API_SPEC.md` §6):
 * the new pairing and the freshly derived status.
 */
#[MapName(SnakeCaseMapper::class)]
final class CitationPairingData extends BaseData
{
    public function __construct(
        public string $id,
        public CitationStatus $status,
        public ?CitationReferencePreviewData $reference = null,
    ) {}

    public static function forCitation(ResearchedDocumentCitation $citation, CitationStatus $status): self
    {
        return new self(
            id: $citation->getKey(),
            status: $status,
            reference: $citation->reference === null
                ? null
                : CitationReferencePreviewData::fromModel($citation->reference),
        );
    }
}
