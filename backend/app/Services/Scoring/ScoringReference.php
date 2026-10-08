<?php

namespace App\Services\Scoring;

use App\Models\ResearchedDocumentReference;
use App\Services\Crossref\DoiNormalizer;

/**
 * Pure scoring input for one bibliography entry.
 *
 * {@see self::fromModel()} is the only place scoring touches an Eloquent model;
 * everything downstream is a value object.
 */
final class ScoringReference
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $title,
        public readonly ?string $authors,
        public readonly ?string $publicationName,
        public readonly ?int $publicationYear,
        public readonly ?string $doi,
        public readonly bool $doiValid,
    ) {}

    public static function fromModel(ResearchedDocumentReference $reference, DoiNormalizer $normalizer): self
    {
        $doi = $normalizer->normalize($reference->doi);

        return new self(
            id: (string) $reference->getKey(),
            title: $reference->title,
            authors: $reference->authors,
            publicationName: $reference->publication_name,
            publicationYear: $reference->publication_year,
            doi: $doi,
            doiValid: $doi !== null && $normalizer->isValid($doi),
        );
    }

    public function hasDoi(): bool
    {
        return $this->doi !== null;
    }
}
