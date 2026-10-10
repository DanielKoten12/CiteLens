<?php

namespace App\Services\Evaluation;

/**
 * Aggregate metrics of one citation-resolution evaluation run.
 *
 * `precision`/`recall` treat a committed pair as a positive and the dataset's
 * `expected_reference_id` as ground truth; `hallucination_*` treat a predicted
 * `unmatched` as a positive hallucination verdict.
 */
final class CitationEvaluationResult
{
    /**
     * @param  array<string, array{committed: int, correct: int}>  $byMethod
     * @param  list<array<string, mixed>>  $documents
     */
    public function __construct(
        public readonly int $totalCitations = 0,
        public readonly int $expectedPairs = 0,
        public readonly int $committedPairs = 0,
        public readonly int $correctPairs = 0,
        public readonly int $unresolved = 0,
        public readonly int $unmatched = 0,
        public readonly int $expectedUnmatched = 0,
        public readonly int $correctUnmatched = 0,
        public readonly array $byMethod = [],
        public readonly array $documents = [],
    ) {}

    public function pairPrecision(): ?float
    {
        return $this->ratio($this->correctPairs, $this->committedPairs);
    }

    public function pairRecall(): ?float
    {
        return $this->ratio($this->correctPairs, $this->expectedPairs);
    }

    public function pairF1(): ?float
    {
        $precision = $this->pairPrecision();
        $recall = $this->pairRecall();

        if ($precision === null || $recall === null || ($precision + $recall) === 0.0) {
            return null;
        }

        return 2 * $precision * $recall / ($precision + $recall);
    }

    public function hallucinationPrecision(): ?float
    {
        return $this->ratio($this->correctUnmatched, $this->unmatched);
    }

    public function hallucinationRecall(): ?float
    {
        return $this->ratio($this->correctUnmatched, $this->expectedUnmatched);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_citations' => $this->totalCitations,
            'expected_pairs' => $this->expectedPairs,
            'committed_pairs' => $this->committedPairs,
            'correct_pairs' => $this->correctPairs,
            'unresolved' => $this->unresolved,
            'unmatched' => $this->unmatched,
            'expected_unmatched' => $this->expectedUnmatched,
            'correct_unmatched' => $this->correctUnmatched,
            'pair_precision' => $this->pairPrecision(),
            'pair_recall' => $this->pairRecall(),
            'pair_f1' => $this->pairF1(),
            'hallucination_precision' => $this->hallucinationPrecision(),
            'hallucination_recall' => $this->hallucinationRecall(),
            'by_method' => $this->byMethod,
            'documents' => $this->documents,
        ];
    }

    private function ratio(int $numerator, int $denominator): ?float
    {
        if ($denominator === 0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }
}
