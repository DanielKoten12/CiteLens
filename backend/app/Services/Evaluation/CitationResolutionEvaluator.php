<?php

namespace App\Services\Evaluation;

use App\Enums\CitationResolutionState;
use App\Services\Citations\CitationBatchResolver;
use App\Services\Citations\CitationMarkerParser;
use App\Services\Citations\CitationReference;
use App\Services\Citations\CitationResolutionInput;
use App\Services\Scoring\AuthorMatcher;

/**
 * Runs the real citation-resolution engine over a labeled JSON dataset and
 * computes pairing/hallucination metrics (D-05.1-08).
 *
 * The evaluator is deterministic and uses the same pure engine as production, so
 * threshold/weight changes can be calibrated without touching the resolver.
 * The seed dataset under `tests/Fixtures/citations/evaluation/` is a regression
 * and calibration tool; real labeled data belongs to Phase 07.
 */
final class CitationResolutionEvaluator
{
    public function __construct(
        private readonly CitationMarkerParser $parser,
        private readonly CitationBatchResolver $batchResolver,
        private readonly AuthorMatcher $authorMatcher,
    ) {}

    /**
     * @param  array{documents?: list<array<string, mixed>>}  $dataset
     */
    public function evaluate(array $dataset): CitationEvaluationResult
    {
        $documents = [];

        $total = 0;
        $expectedPairs = 0;
        $committedPairs = 0;
        $correctPairs = 0;
        $unresolved = 0;
        $unmatched = 0;
        $expectedUnmatched = 0;
        $correctUnmatched = 0;
        $byMethod = [];

        foreach ($dataset['documents'] ?? [] as $document) {
            $row = $this->evaluateDocument($document);
            $documents[] = $row;

            $total += $row['total'];
            $expectedPairs += $row['expected_pairs'];
            $committedPairs += $row['committed_pairs'];
            $correctPairs += $row['correct_pairs'];
            $unresolved += $row['unresolved'];
            $unmatched += $row['unmatched'];
            $expectedUnmatched += $row['expected_unmatched'];
            $correctUnmatched += $row['correct_unmatched'];

            foreach ($row['by_method'] as $method => $counts) {
                $byMethod[$method]['committed'] = ($byMethod[$method]['committed'] ?? 0) + $counts['committed'];
                $byMethod[$method]['correct'] = ($byMethod[$method]['correct'] ?? 0) + $counts['correct'];
            }
        }

        return new CitationEvaluationResult(
            totalCitations: $total,
            expectedPairs: $expectedPairs,
            committedPairs: $committedPairs,
            correctPairs: $correctPairs,
            unresolved: $unresolved,
            unmatched: $unmatched,
            expectedUnmatched: $expectedUnmatched,
            correctUnmatched: $correctUnmatched,
            byMethod: $byMethod,
            documents: $documents,
        );
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function evaluateDocument(array $document): array
    {
        $rawReferences = array_values($document['references'] ?? []);
        $rawCitations = array_values($document['citations'] ?? []);

        $references = [];

        foreach ($rawReferences as $index => $reference) {
            $references[] = new CitationReference(
                id: (string) $reference['id'],
                authors: $this->authorMatcher->names($reference['authors'] ?? null),
                publicationYear: isset($reference['publication_year']) ? (int) $reference['publication_year'] : null,
                bibliographyIndex: $index + 1,
            );
        }

        $referenceIdsByIndex = array_map(
            static fn (array $reference): string => (string) $reference['id'],
            $rawReferences,
        );

        $inputs = [];
        $expected = [];

        foreach ($rawCitations as $citation) {
            $hintIndex = isset($citation['hint_index']) ? (int) $citation['hint_index'] : null;

            $inputs[] = new CitationResolutionInput(
                citationId: (string) $citation['id'],
                marker: $this->parser->parse($citation['marker'] ?? null, $citation['text'] ?? null),
                hintedReferenceId: $hintIndex === null ? null : ($referenceIdsByIndex[$hintIndex] ?? null),
                hintIndex: $hintIndex,
            );

            $expected[(string) $citation['id']] = $citation['expected_reference_id'] ?? null;
        }

        $resolutions = $this->batchResolver->resolve($references, $inputs);

        $row = [
            'name' => (string) ($document['name'] ?? 'unnamed'),
            'total' => count($inputs),
            'expected_pairs' => 0,
            'committed_pairs' => 0,
            'correct_pairs' => 0,
            'unresolved' => 0,
            'unmatched' => 0,
            'expected_unmatched' => 0,
            'correct_unmatched' => 0,
            'by_method' => [],
        ];

        foreach ($inputs as $input) {
            $expectedId = $expected[$input->citationId] ?? null;
            $resolution = $resolutions[$input->citationId];

            if ($expectedId === null) {
                $row['expected_unmatched']++;
            } else {
                $row['expected_pairs']++;
            }

            if ($resolution->state === CitationResolutionState::Paired) {
                $row['committed_pairs']++;
                $method = $resolution->method?->value ?? 'none';
                $row['by_method'][$method] ??= ['committed' => 0, 'correct' => 0];
                $row['by_method'][$method]['committed']++;

                if ($expectedId !== null && $resolution->referenceId === $expectedId) {
                    $row['correct_pairs']++;
                    $row['by_method'][$method]['correct']++;
                }

                continue;
            }

            if ($resolution->state === CitationResolutionState::Unresolved) {
                $row['unresolved']++;

                continue;
            }

            $row['unmatched']++;

            if ($expectedId === null) {
                $row['correct_unmatched']++;
            }
        }

        return $row;
    }
}
