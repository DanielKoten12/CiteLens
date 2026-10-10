<?php

namespace App\Services\Findings;

use App\Enums\CitationStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingType;
use App\Enums\ReferenceFindingStatus;
use App\Models\ResearchedDocument;
use App\Services\Citations\CitationStatusResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the derived findings/highlights feed
 * (`GET /documents/{document}/findings`, `docs/API_SPEC.md` §7).
 *
 * The feed is a read-only union of problem reference findings and problematic
 * citations. It is built as one portable `unionAll` subquery so pagination and
 * `meta` are correct without loading the whole document, and the type/severity
 * mapping is composed from the enums (`toFindingType()`, `toSeverity()`) so the
 * feed cannot drift from the API contract.
 *
 * Ordering is document position (`text_start_offset` ASC, nulls last) then the
 * row id (OQ-09). `sort_offset` uses a large sentinel instead of `NULLS LAST`
 * so ordering is identical on SQLite/MySQL/Postgres (D-05-12).
 */
final class FindingsFeedQuery
{
    /**
     * Sentinel above every plausible character offset; used so offset-less rows
     * sort last on all supported engines.
     */
    private const int MAX_SORT_OFFSET = 2147483647;

    public function __construct(
        private readonly CitationStatusResolver $resolver,
    ) {}

    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(
        ResearchedDocument $document,
        ?FindingType $type = null,
        ?FindingSeverity $severity = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $feed = DB::query()
            ->fromSub($this->referenceSide($document)->unionAll($this->citationSide($document)), 'feed')
            ->when($type !== null, fn (Builder $query): Builder => $query->where('feed.type', $type->value))
            ->when($severity !== null, fn (Builder $query): Builder => $query->where('feed.severity', $severity->value))
            ->orderBy('feed.sort_offset')
            ->orderBy('feed.sort_id');

        return $feed->paginate($perPage)->withQueryString();
    }

    /**
     * Every finding that is a problem per `docs/API_SPEC.md` §2.6, including
     * `pending` (OQ-09).
     *
     * @return list<ReferenceFindingStatus>
     */
    private function problemStatuses(): array
    {
        return array_values(array_filter(
            ReferenceFindingStatus::cases(),
            static fn (ReferenceFindingStatus $status): bool => $status->toFindingType() !== null,
        ));
    }

    private function referenceSide(ResearchedDocument $document): Builder
    {
        $statuses = $this->problemStatuses();

        return DB::table('reference_findings as f')
            ->join('researched_document_references as r', 'r.id', '=', 'f.researched_document_reference_id')
            ->where('f.researched_document_id', $document->getKey())
            ->whereIn('f.status', array_map(static fn (ReferenceFindingStatus $status): string => $status->value, $statuses))
            ->select([
                DB::raw("'reference' as source"),
                'f.id as entity_id',
                DB::raw($this->typeCase($statuses).' as type'),
                DB::raw($this->severityCase($statuses).' as severity'),
                'f.reason as message',
                'f.researched_document_reference_id as reference_id',
                DB::raw('NULL as citation_id'),
                'r.raw_text as text',
                'r.text_start_offset as start_offset',
                'r.text_end_offset as end_offset',
                DB::raw('COALESCE(r.text_start_offset, '.self::MAX_SORT_OFFSET.') as sort_offset'),
                'f.id as sort_id',
            ]);
    }

    private function citationSide(ResearchedDocument $document): Builder
    {
        $derived = $this->resolver->sqlExpression('c.resolution_state', 'f.status');
        $hallucination = CitationStatus::Hallucination->value;
        $unresolved = CitationStatus::Unresolved->value;
        $unreliable = CitationStatus::Unreliable->value;

        $typeCase = "CASE WHEN ({$derived}) = '{$hallucination}'"
            ." THEN '".FindingType::CitationHallucination->value."'"
            ." WHEN ({$derived}) = '{$unresolved}'"
            ." THEN '".FindingType::CitationUnresolved->value."'"
            ." ELSE '".FindingType::CitationUnreliable->value."' END";

        // `unreliable` inherits the paired finding's severity (always `high`
        // under the canonical mapping, but kept generic); `unresolved` is the
        // medium-confidence bucket and `hallucination` is always high.
        $severityCase = "CASE WHEN ({$derived}) = '{$hallucination}'"
            ." THEN '".FindingSeverity::High->value."'"
            ." WHEN ({$derived}) = '{$unresolved}'"
            ." THEN '".FindingSeverity::Medium->value."'"
            ." WHEN f.status IN ('".ReferenceFindingStatus::Invalid->value."','".ReferenceFindingStatus::NotFound->value."') THEN '".FindingSeverity::High->value."'"
            ." ELSE '".FindingSeverity::Info->value."' END";

        $messageCase = "CASE WHEN ({$derived}) = '{$hallucination}'"
            ." THEN '".CitationStatus::Hallucination->message()."'"
            ." WHEN ({$derived}) = '{$unresolved}'"
            ." THEN '".CitationStatus::Unresolved->message()."'"
            ." ELSE '".CitationStatus::Unreliable->message()."' END";

        return DB::table('researched_document_citations as c')
            ->leftJoin('researched_document_references as r', 'r.id', '=', 'c.researched_document_reference_id')
            ->leftJoin('reference_findings as f', 'f.researched_document_reference_id', '=', 'r.id')
            ->where('c.researched_document_id', $document->getKey())
            ->whereRaw("({$derived}) IN ('{$unreliable}', '{$unresolved}', '{$hallucination}')")
            ->select([
                DB::raw("'citation' as source"),
                'c.id as entity_id',
                DB::raw($typeCase.' as type'),
                DB::raw($severityCase.' as severity'),
                DB::raw($messageCase.' as message'),
                'c.researched_document_reference_id as reference_id',
                'c.id as citation_id',
                'c.citation_text as text',
                'c.text_start_offset as start_offset',
                'c.text_end_offset as end_offset',
                DB::raw('COALESCE(c.text_start_offset, '.self::MAX_SORT_OFFSET.') as sort_offset'),
                'c.id as sort_id',
            ]);
    }

    /**
     * `CASE f.status WHEN 'suspicious' THEN 'reference_suspicious' … END`.
     *
     * @param  list<ReferenceFindingStatus>  $statuses
     */
    private function typeCase(array $statuses): string
    {
        return $this->caseExpression(
            'f.status',
            $statuses,
            static fn (ReferenceFindingStatus $status): string => (string) $status->toFindingType()?->value,
        );
    }

    /**
     * `CASE f.status WHEN 'invalid' THEN 'high' … END`.
     *
     * @param  list<ReferenceFindingStatus>  $statuses
     */
    private function severityCase(array $statuses): string
    {
        return $this->caseExpression(
            'f.status',
            $statuses,
            static fn (ReferenceFindingStatus $status): string => (string) $status->toSeverity()?->value,
        );
    }

    /**
     * @param  list<ReferenceFindingStatus>  $statuses
     * @param  callable(ReferenceFindingStatus): string  $mapper
     */
    private function caseExpression(string $column, array $statuses, callable $mapper): string
    {
        $sql = "CASE {$column}";

        foreach ($statuses as $status) {
            $value = $mapper($status);

            if ($value === '') {
                continue;
            }

            $sql .= " WHEN '{$status->value}' THEN '{$value}'";
        }

        return $sql.' END';
    }
}
