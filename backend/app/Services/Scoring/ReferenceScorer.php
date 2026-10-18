<?php

namespace App\Services\Scoring;

use App\Services\Analysis\EmbeddingIndex;
use App\Services\Crossref\CrossrefWorkData;

/**
 * Pure candidate ranking engine.
 *
 * Combines normalized string similarity, author matching, SBERT cosine
 * similarity and exact year/DOI agreement into a weighted score, sorts
 * candidates best-first and assigns `rank = 1..N`. It has no Eloquent, HTTP or
 * container dependency; embeddings arrive as an {@see EmbeddingIndex} and
 * missing signals are `null` so their weight is redistributed instead of
 * penalized (D-04-10).
 */
final class ReferenceScorer
{
    private const CONFLICT_FLOOR = 0.50;

    public function __construct(
        private readonly ScoringConfig $config,
        private readonly StringSimilarity $strings,
        private readonly AuthorMatcher $authors,
        private readonly SemanticSimilarity $semantic,
        private readonly MatchReasonBuilder $reasons,
    ) {}

    /**
     * @param  list<CrossrefWorkData>  $candidates
     * @return list<ScoredCandidate> ranked best-first, `rank = 1..N`
     */
    public function score(ScoringReference $reference, array $candidates, EmbeddingIndex $embeddings): array
    {
        if ($candidates === []) {
            return [];
        }

        $weights = $this->config->weights();
        $scored = [];

        foreach ($candidates as $index => $candidate) {
            $scored[$index] = $this->scoreCandidate($reference, $candidate, $index, $embeddings, $weights);
        }

        uksort($scored, function (int $left, int $right) use ($scored): int {
            $a = $scored[$left];
            $b = $scored[$right];

            return [
                $b->confidence(),
                $b->breakdown->doiMatch ? 1 : 0,
                $b->breakdown->signal('title') ?? 0.0,
                $left,
            ] <=> [
                $a->confidence(),
                $a->breakdown->doiMatch ? 1 : 0,
                $a->breakdown->signal('title') ?? 0.0,
                $right,
            ];
        });

        $ranked = [];
        $rank = 1;

        foreach ($scored as $candidate) {
            $ranked[] = new ScoredCandidate(
                work: $candidate->work,
                breakdown: $candidate->breakdown,
                rank: $rank++,
                matchReason: $candidate->matchReason,
            );
        }

        return $ranked;
    }

    /**
     * @param  array{title: float, authors: float, journal: float, year: float}  $weights
     */
    private function scoreCandidate(
        ScoringReference $reference,
        CrossrefWorkData $candidate,
        int $index,
        EmbeddingIndex $embeddings,
        array $weights,
    ): ScoredCandidate {
        $titleString = $this->strings->levenshtein($reference->title, $candidate->title);
        $titleSemantic = $this->titleSemantic($reference, $candidate, $index, $embeddings);
        $title = $this->blendTitle($titleString, $titleSemantic);

        $signals = [
            'title' => $title,
            'authors' => $this->authors->similarity($reference->authors, $candidate->authors),
            'journal' => $this->strings->levenshtein($reference->publicationName, $candidate->containerTitle),
            'year' => $this->yearSignal($reference->publicationYear, $candidate->publicationYear),
        ];

        $final = $this->weightedAverage($signals, $weights);
        $doiMatch = $this->doiMatch($reference, $candidate);
        $conflicts = $this->conflicts($signals);

        if ($doiMatch && $conflicts === []) {
            // A resolved DOI whose metadata agrees short-circuits to the top rank
            // (D-04-03); a conflicting DOI keeps its low score and can be outranked.
            $final = max($final, min(1.0, $this->config->validThreshold() + 0.0001));
        }

        $breakdown = new ScoreBreakdown(
            signals: $signals,
            final: round(max(0.0, min(1.0, $final)), 4),
            doiMatch: $doiMatch,
            semanticUsed: $titleSemantic !== null,
            semanticDegraded: ! $embeddings->isAvailable() && $this->config->semanticEnabled(),
            conflicts: $conflicts,
        );

        return new ScoredCandidate(
            work: $candidate,
            breakdown: $breakdown,
            rank: 0,
            matchReason: $this->reasons->forCandidate($breakdown),
        );
    }

    private function titleSemantic(
        ScoringReference $reference,
        CrossrefWorkData $candidate,
        int $index,
        EmbeddingIndex $embeddings,
    ): ?float {
        if (! $this->config->semanticEnabled() || ! $embeddings->isAvailable()) {
            return null;
        }

        return $this->semantic->cosine(
            $embeddings->vectorFor(EmbeddingIndex::referenceKey($reference->id)),
            $embeddings->vectorFor(EmbeddingIndex::candidateKey($reference->id, $index)),
        );
    }

    private function blendTitle(?float $string, ?float $semantic): ?float
    {
        if ($semantic === null) {
            return $string;
        }

        if ($string === null) {
            return $semantic;
        }

        $blend = $this->config->semanticTitleBlend();

        return max(0.0, min(1.0, ($semantic * $blend) + ($string * (1.0 - $blend))));
    }

    private function yearSignal(?int $referenceYear, ?int $candidateYear): ?float
    {
        if ($referenceYear === null || $candidateYear === null) {
            return null;
        }

        return abs($referenceYear - $candidateYear) <= $this->config->yearTolerance() ? 1.0 : 0.0;
    }

    private function doiMatch(ScoringReference $reference, CrossrefWorkData $candidate): bool
    {
        return $reference->doi !== null
            && $candidate->doi !== null
            && $reference->doi === $candidate->doi;
    }

    /**
     * @param  array{title: ?float, authors: ?float, journal: ?float, year: ?float}  $signals
     * @param  array{title: float, authors: float, journal: float, year: float}  $weights
     */
    private function weightedAverage(array $signals, array $weights): float
    {
        $weighted = 0.0;
        $totalWeight = 0.0;

        foreach ($signals as $key => $value) {
            if ($value === null) {
                continue;
            }

            $weight = $weights[$key] ?? 0.0;

            $weighted += $value * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight <= 0.0 ? 0.0 : max(0.0, min(1.0, $weighted / $totalWeight));
    }

    /**
     * @param  array{title: ?float, authors: ?float, journal: ?float, year: ?float}  $signals
     * @return list<string>
     */
    private function conflicts(array $signals): array
    {
        $conflicts = [];

        foreach (['title', 'authors', 'journal'] as $field) {
            $value = $signals[$field] ?? null;

            if ($value !== null && $value < self::CONFLICT_FLOOR) {
                $conflicts[] = $field;
            }
        }

        if (($signals['year'] ?? null) === 0.0) {
            $conflicts[] = 'year';
        }

        return $conflicts;
    }
}
