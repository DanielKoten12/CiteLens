<?php

namespace App\Data\Inference;

use App\Data\BaseData;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Response body of `POST /v1/embeddings` wrapped in its `data` object
 * (`docs/API_SPEC.md` §9).
 *
 * The vector list is a plain list of float lists, not a `Data` collection, so
 * the constructor validates that every vector matches the declared dimension.
 * This keeps a malformed SBERT response from silently producing wrong cosine
 * similarities in Phase 04.
 */
#[MapName(SnakeCaseMapper::class)]
final class EmbeddingResultData extends BaseData
{
    /**
     * @param  list<list<float>>  $embeddings
     *
     * @throws InvalidArgumentException when `$embeddings` is not a list of vectors of `$dimensions` length
     */
    public function __construct(
        public string $model,
        public int $dimensions,
        public array $embeddings = [],
    ) {
        foreach ($embeddings as $vector) {
            if (! is_array($vector) || count($vector) !== $dimensions) {
                throw new InvalidArgumentException(
                    "An embedding vector must contain exactly {$dimensions} values."
                );
            }
        }
    }

    /**
     * The embedding vectors, in the same order as the requested texts.
     *
     * @return list<list<float>>
     */
    public function vectors(): array
    {
        return $this->embeddings;
    }

    public function count(): int
    {
        return count($this->embeddings);
    }
}
