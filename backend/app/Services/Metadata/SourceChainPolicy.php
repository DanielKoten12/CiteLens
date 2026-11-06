<?php

namespace App\Services\Metadata;

use App\Enums\MetadataSource;
use App\Services\Scoring\ScoringConfig;
use App\Services\Scoring\StringSimilarity;
use LogicException;

/**
 * Pure decisions for the metadata source chains: gating, stop policy, outage
 * policy and budgets. No I/O, no per-document state.
 *
 * Configuration is read from `config/analysis.php`, except the per-source
 * request budget which already lives in each source's `services.*` block (the
 * single place the source's politeness knobs are defined). Invalid values fail
 * loudly at the first run instead of silently degrading to a guess (D-09-04).
 */
final class SourceChainPolicy
{
    public const STOP_PLAUSIBLE_TITLE = 'plausible_title';

    public const STOP_PLAUSIBLE_TITLE_WITH_DOI = 'plausible_title_with_doi';

    public const FATAL = 'fatal';

    public const DEGRADE = 'degrade';

    /**
     * Shortest reference title worth a fallback query; shorter titles are too
     * ambiguous. Primary sources still run.
     */
    private const MIN_TITLE_LENGTH = 8;

    /**
     * `services.*` keys that own a source's per-document request budget.
     * Crossref is primary and intentionally unbudgeted.
     *
     * @var array<string, string>
     */
    private const BUDGET_KEYS = [
        'garuda' => 'services.garuda.max_requests_per_document',
        'semantic_scholar' => 'services.semantic_scholar.max_requests_per_document',
        'openalex' => 'services.openalex.max_requests_per_document',
    ];

    public function __construct(
        private readonly ScoringConfig $scoring,
        private readonly StringSimilarity $strings,
    ) {
        $this->assertValidConfiguration();
    }

    public function stopPolicy(): string
    {
        return (string) config('analysis.metadata.stop_policy', self::STOP_PLAUSIBLE_TITLE_WITH_DOI);
    }

    public function shouldGate(?string $title): bool
    {
        return mb_strlen(trim((string) $title)) >= self::MIN_TITLE_LENGTH;
    }

    /**
     * Whether the collected candidates are enough to stop the fallback chain.
     *
     * @param  list<WorkMetadata>  $candidates
     */
    public function isSatisfied(?string $title, array $candidates): bool
    {
        if ($candidates === [] || ! $this->shouldGate($title)) {
            return false;
        }

        $requiresDoi = $this->stopPolicy() === self::STOP_PLAUSIBLE_TITLE_WITH_DOI;
        $threshold = $this->scoring->fallbackTitleThreshold();

        foreach ($candidates as $candidate) {
            $similarity = $this->strings->levenshtein($title, $candidate->title);

            if ($similarity === null || $similarity < $threshold) {
                continue;
            }

            if (! $requiresDoi || $candidate->doi !== null) {
                return true;
            }
        }

        return false;
    }

    public function failurePolicy(MetadataSource $source): string
    {
        $policy = config('analysis.metadata.failure_policies.'.$source->value);

        if (! is_string($policy) || ! in_array($policy, [self::FATAL, self::DEGRADE], true)) {
            throw new LogicException(sprintf(
                'Invalid metadata failure policy for [%s]: expected "%s" or "%s".',
                $source->value,
                self::FATAL,
                self::DEGRADE,
            ));
        }

        return $policy;
    }

    public function isFatal(MetadataSource $source): bool
    {
        return $this->failurePolicy($source) === self::FATAL;
    }

    public function isDegradable(MetadataSource $source): bool
    {
        return $this->failurePolicy($source) === self::DEGRADE;
    }

    /**
     * Per-document request budget for a source; 0 = unlimited.
     */
    public function perSourceBudget(MetadataSource $source): int
    {
        $key = self::BUDGET_KEYS[$source->value] ?? null;

        if ($key === null) {
            return 0;
        }

        return max(0, (int) config($key, 0));
    }

    /**
     * Whether an optional source must be skipped for this reference.
     *
     * Primary sources are never budgeted (only fallback lookups count toward
     * the document-wide caps). Callers pass `$fallback = false` for them.
     */
    public function budgetExhausted(MetadataSource $source, SourceChainState $state, bool $fallback = true): bool
    {
        if (! $fallback) {
            return false;
        }

        $budget = $this->perSourceBudget($source);

        if ($budget > 0 && $state->lookups($source) >= $budget) {
            return true;
        }

        return $state->fallbackBudgetExhausted() || $state->fallbackTimeExhausted();
    }

    /**
     * Validate the declarative configuration once, at construction.
     */
    public function assertValidConfiguration(): void
    {
        $stopPolicy = $this->stopPolicy();

        if (! in_array($stopPolicy, [self::STOP_PLAUSIBLE_TITLE, self::STOP_PLAUSIBLE_TITLE_WITH_DOI], true)) {
            throw new LogicException(sprintf(
                'Invalid analysis.metadata.stop_policy [%s]: expected "%s" or "%s".',
                $stopPolicy,
                self::STOP_PLAUSIBLE_TITLE,
                self::STOP_PLAUSIBLE_TITLE_WITH_DOI,
            ));
        }

        foreach (MetadataSource::cases() as $source) {
            $this->failurePolicy($source);
        }
    }
}
