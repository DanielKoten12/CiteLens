<?php

namespace App\Console\Commands;

use App\Services\Evaluation\CitationResolutionEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

/**
 * Runs the citation-resolution engine over a labeled JSON dataset and prints
 * pairing/hallucination metrics (D-05.1-08).
 *
 * Dataset shape:
 * ```
 * { "documents": [ {
 *     "name": "…",
 *     "references": [ { "id", "authors", "publication_year"?, "text_start_offset"? } ],
 *     "citations":  [ { "id", "marker"?, "text"?, "hint_index"?, "expected_reference_id"? } ]
 * } ] }
 * ```
 */
final class EvaluateCitationResolutionCommand extends Command
{
    protected $signature = 'citations:evaluate
                            {dataset : Path to a JSON evaluation dataset}
                            {--json= : Write the full report as JSON to this path}';

    protected $description = 'Evaluate the citation-resolution engine against a labeled dataset';

    public function handle(CitationResolutionEvaluator $evaluator): int
    {
        $argument = (string) $this->argument('dataset');
        $path = $this->resolvePath($argument);

        if ($path === null) {
            $this->error("Dataset not found: {$argument}");

            return self::FAILURE;
        }

        try {
            $dataset = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("Dataset is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($dataset) || ! is_array($dataset['documents'] ?? null)) {
            $this->error('Dataset must contain a "documents" array.');

            return self::FAILURE;
        }

        try {
            $result = $evaluator->evaluate($dataset);
        } catch (Throwable $exception) {
            $this->error("Evaluation failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->table(['Metric', 'Value'], [
            ['total_citations', (string) $result->totalCitations],
            ['expected_pairs', (string) $result->expectedPairs],
            ['committed_pairs', (string) $result->committedPairs],
            ['correct_pairs', (string) $result->correctPairs],
            ['pair_precision', $this->format($result->pairPrecision())],
            ['pair_recall', $this->format($result->pairRecall())],
            ['pair_f1', $this->format($result->pairF1())],
            ['unresolved', (string) $result->unresolved],
            ['unmatched', (string) $result->unmatched],
            ['hallucination_precision', $this->format($result->hallucinationPrecision())],
            ['hallucination_recall', $this->format($result->hallucinationRecall())],
        ]);

        foreach ($result->byMethod as $method => $counts) {
            $this->line(sprintf('  %-16s committed %d, correct %d', $method, $counts['committed'], $counts['correct']));
        }

        if ($result->documents !== []) {
            $this->table(
                ['Document', 'Total', 'Committed', 'Correct', 'Unmatched'],
                array_map(static fn (array $row): array => [
                    $row['name'],
                    (string) $row['total'],
                    (string) $row['committed_pairs'],
                    (string) $row['correct_pairs'],
                    (string) $row['unmatched'],
                ], $result->documents),
            );
        }

        $jsonPath = $this->option('json');

        if (is_string($jsonPath) && $jsonPath !== '') {
            $this->writeReport($jsonPath, $result->toArray());
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $path): ?string
    {
        if (is_file($path)) {
            return $path;
        }

        $basePath = base_path($path);

        return is_file($basePath) ? $basePath : null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(string $path, array $report): void
    {
        File::ensureDirectoryExists(dirname($path));

        $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || file_put_contents($path, $encoded) === false) {
            $this->error("Could not write report to {$path}");

            return;
        }

        $this->info("Report written to {$path}");
    }

    private function format(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value, 4);
    }
}
