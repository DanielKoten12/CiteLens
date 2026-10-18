<?php

use App\Data\Inference\EmbeddingResultData;
use App\Data\Inference\ExtractionResultData;
use App\Data\Inference\HealthData;
use Tests\Support\Fixtures;

it('maps the extraction contract to DTOs', function () {
    $payload = Fixtures::json('inference/extract')['data'];

    $result = ExtractionResultData::from($payload);

    expect($result->references)->toHaveCount(3)
        ->and($result->citations)->toHaveCount(3)
        ->and($result->isEmpty())->toBeFalse();

    $first = $result->references[0];

    expect($first->title)->toBe('Deep learning')
        ->and($first->doi)->toBe('https://doi.org/10.1038/NATURE14539')
        ->and($first->publicationName)->toBe('Nature')
        ->and($first->publicationYear)->toBe(2015)
        ->and($first->textStartOffset)->toBe(8120)
        ->and($first->locations)->toHaveCount(2)
        ->and($first->locations[1]->pageNumber)->toBe(13)
        ->and($first->locations[1]->height)->toBe(12.0);

    $third = $result->references[2];

    expect($third->doi)->toBe('doi:not-a-doi')
        ->and($third->locations)->toBe([]);

    $citation = $result->citations[0];

    expect($citation->citationText)->toBe('(LeCun et al., 2015)')
        ->and($citation->referenceIndex)->toBe(0)
        ->and($citation->occurrenceIndex)->toBe(0)
        ->and($citation->locations)->toHaveCount(1);

    $unresolved = $result->citations[2];

    expect($unresolved->occurrenceIndex)->toBeNull()
        ->and($unresolved->referenceIndex)->toBeNull();
});

it('defaults optional wire fields to null', function () {
    $result = ExtractionResultData::from([
        'references' => [['title' => 'Only a title']],
        'citations' => [],
    ]);

    $reference = $result->references[0];

    expect($reference->doi)->toBeNull()
        ->and($reference->publicationYear)->toBeNull()
        ->and($reference->locations)->toBe([])
        ->and($result->citations)->toBe([]);
});

it('treats an empty extraction as empty', function () {
    $result = ExtractionResultData::from(['references' => [], 'citations' => []]);

    expect($result->isEmpty())->toBeTrue();
});

it('accepts a well-formed embedding payload', function () {
    $result = EmbeddingResultData::from([
        'model' => 'sbert',
        'dimensions' => 3,
        'embeddings' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
    ]);

    expect($result->model)->toBe('sbert')
        ->and($result->dimensions)->toBe(3)
        ->and($result->count())->toBe(2)
        ->and($result->vectors()[1])->toBe([0.4, 0.5, 0.6]);
});

it('rejects an embedding vector with the wrong dimension', function () {
    expect(fn () => EmbeddingResultData::from([
        'model' => 'sbert',
        'dimensions' => 3,
        'embeddings' => [[0.1, 0.2]],
    ]))->toThrow(InvalidArgumentException::class);
});

it('maps the health payload', function () {
    $health = HealthData::from(['status' => 'ok', 'grobid' => 'up', 'sbert' => 'up']);

    expect($health->isHealthy())->toBeTrue()
        ->and($health->grobid)->toBe('up');

    expect(HealthData::from(['status' => 'degraded'])->isHealthy())->toBeFalse();
});
