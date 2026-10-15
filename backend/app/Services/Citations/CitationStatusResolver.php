<?php

namespace App\Services\Citations;

use App\Enums\CitationResolutionState;
use App\Enums\CitationStatus;
use App\Enums\ReferenceFindingStatus;
use InvalidArgumentException;

/**
 * The single implementation of the derived citation-status rule
 * (`docs/API_SPEC.md` §2.6), available both as a PHP value and as a portable SQL
 * expression so the summary, filters and DTOs cannot drift.
 *
 * The PHP path delegates to {@see CitationStatus::derive()}; the SQL path builds
 * a `CASE` from the same enum values. A consistency test asserts both agree on
 * the same fixture.
 */
final class CitationStatusResolver
{
    /**
     * Derive the status for one citation.
     */
    public function resolve(CitationResolutionState $state, ?ReferenceFindingStatus $findingStatus): CitationStatus
    {
        return CitationStatus::derive($state, $findingStatus);
    }

    /**
     * A portable `CASE` expression yielding the derived status as its enum value.
     *
     * `$stateColumn` is the persisted `resolution_state`; `$findingStatusColumn`
     * is the finding status from a LEFT JOIN (nullable when paired without a
     * finding). Both must be trusted SQL identifiers — they are validated, never
     * taken from user input.
     */
    public function sqlExpression(string $stateColumn, string $findingStatusColumn): string
    {
        $this->assertIdentifier($stateColumn);
        $this->assertIdentifier($findingStatusColumn);

        $unresolvedState = CitationResolutionState::Unresolved->value;
        $unmatchedState = CitationResolutionState::Unmatched->value;
        $unresolved = CitationStatus::Unresolved->value;
        $hallucination = CitationStatus::Hallucination->value;
        $pending = CitationStatus::Pending->value;
        $valid = CitationStatus::Valid->value;
        $unreliable = CitationStatus::Unreliable->value;
        $validFinding = ReferenceFindingStatus::Valid->value;
        $suspiciousFinding = ReferenceFindingStatus::Suspicious->value;

        return 'CASE'
            ." WHEN {$stateColumn} = '{$unresolvedState}' THEN '{$unresolved}'"
            ." WHEN {$stateColumn} = '{$unmatchedState}' THEN '{$hallucination}'"
            ." WHEN {$findingStatusColumn} IS NULL OR {$findingStatusColumn} = '{$pending}' THEN '{$pending}'"
            ." WHEN {$findingStatusColumn} IN ('{$validFinding}','{$suspiciousFinding}') THEN '{$valid}'"
            ." ELSE '{$unreliable}'"
            .' END';
    }

    /**
     * Reject anything that is not a simple `column` or `alias.column` identifier.
     */
    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }
    }
}
