<?php

use App\Services\Evaluation\CitationResolutionEvaluator;
use Tests\Support\Fixtures;

it('computes pairing and hallucination metrics from a labeled dataset', function () {
    $dataset = [
        'documents' => [[
            'name' => 'inline',
            'references' => [
                ['id' => 'r1', 'authors' => 'Koten, D.', 'publication_year' => 2023],
                ['id' => 'r2', 'authors' => 'Tani, B.', 'publication_year' => 2021],
            ],
            'citations' => [
                ['id' => 'c1', 'marker' => '(Koten, 2023)', 'expected_reference_id' => 'r1'],
                ['id' => 'c2', 'marker' => '(Tanpa, 2020)', 'expected_reference_id' => null],
                ['id' => 'c3', 'marker' => '[2]', 'expected_reference_id' => 'r2'],
                ['id' => 'c4', 'marker' => 'lihat lampiran', 'expected_reference_id' => null],
            ],
        ]],
    ];

    $result = app(CitationResolutionEvaluator::class)->evaluate($dataset);

    expect($result->totalCitations)->toBe(4)
        ->and($result->expectedPairs)->toBe(2)
        ->and($result->committedPairs)->toBe(2)
        ->and($result->correctPairs)->toBe(2)
        ->and($result->unresolved)->toBe(1)
        ->and($result->unmatched)->toBe(1)
        ->and($result->expectedUnmatched)->toBe(2)
        ->and($result->correctUnmatched)->toBe(1)
        ->and($result->pairPrecision())->toBe(1.0)
        ->and($result->pairRecall())->toBe(1.0)
        ->and($result->pairF1())->toBe(1.0)
        ->and($result->hallucinationPrecision())->toBe(1.0)
        ->and($result->hallucinationRecall())->toBe(0.5)
        ->and($result->byMethod)->toBe([
            'apa' => ['committed' => 1, 'correct' => 1],
            'ieee' => ['committed' => 1, 'correct' => 1],
        ]);
});

it('reports a mismatch as reduced precision', function () {
    $dataset = [
        'documents' => [[
            'name' => 'wrong',
            'references' => [
                ['id' => 'r1', 'authors' => 'Koten, D.', 'publication_year' => 2023],
            ],
            'citations' => [
                ['id' => 'c1', 'marker' => '(Koten, 2023)', 'expected_reference_id' => null],
            ],
        ]],
    ];

    $result = app(CitationResolutionEvaluator::class)->evaluate($dataset);

    expect($result->committedPairs)->toBe(1)
        ->and($result->correctPairs)->toBe(0)
        ->and($result->pairPrecision())->toBe(0.0)
        ->and($result->pairRecall())->toBeNull()
        ->and($result->unmatched)->toBe(0)
        ->and($result->hallucinationPrecision())->toBeNull();
});

it('evaluates the seed dataset without regressions', function () {
    $dataset = Fixtures::json('citations/evaluation/seed');

    $result = app(CitationResolutionEvaluator::class)->evaluate($dataset);

    expect($result->totalCitations)->toBe(9)
        ->and($result->expectedPairs)->toBe(7)
        ->and($result->committedPairs)->toBe(7)
        ->and($result->correctPairs)->toBe(7)
        ->and($result->unresolved)->toBe(1)
        ->and($result->unmatched)->toBe(1)
        ->and($result->correctUnmatched)->toBe(1)
        ->and($result->pairPrecision())->toBe(1.0)
        ->and($result->pairRecall())->toBe(1.0)
        ->and($result->hallucinationPrecision())->toBe(1.0)
        ->and($result->hallucinationRecall())->toBe(0.5)
        ->and($result->documents)->toHaveCount(4);
});
