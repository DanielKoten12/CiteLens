<?php

namespace App\Services\Citation;

use App\Enums\CitationStatus;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Services\Citations\CitationStatusResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read queries for in-text citations (`GET /documents/{document}/citations`,
 * `docs/API_SPEC.md` §6).
 *
 * The derived-status filter goes through the single
 * {@see CitationStatusResolver::sqlExpression()} so it cannot drift from the PHP
 * resolver used by the DTOs (F-DERIV-01). Ordering is document position
 * (`text_start_offset` ASC, nulls last, then `id`) per D-05-03.
 */
final class CitationQueryService
{
    public function __construct(
        private readonly CitationStatusResolver $resolver,
    ) {}

    /**
     * @return LengthAwarePaginator<int, ResearchedDocumentCitation>
     */
    public function paginate(
        ResearchedDocument $document,
        ?CitationStatus $status = null,
        ?string $referenceId = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = ResearchedDocumentCitation::query()
            ->from('researched_document_citations as c')
            ->leftJoin('researched_document_references as r', 'r.id', '=', 'c.researched_document_reference_id')
            ->leftJoin('reference_findings as f', 'f.researched_document_reference_id', '=', 'r.id')
            ->where('c.researched_document_id', $document->getKey())
            ->select('c.*')
            ->with('reference.finding');

        if ($status !== null) {
            $derived = $this->resolver->sqlExpression('c.resolution_state', 'f.status');

            $query->whereRaw("({$derived}) = ?", [$status->value]);
        }

        if ($referenceId !== null) {
            $query->where('c.researched_document_reference_id', $referenceId);
        }

        return $query
            ->orderByRaw('c.text_start_offset IS NULL')
            ->orderBy('c.text_start_offset')
            ->orderBy('c.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Citations that surface as issues in the report — the same three derived
     * statuses as the findings feed (D-06-05).
     *
     * @return Collection<int, ResearchedDocumentCitation>
     */
    public function issues(ResearchedDocument $document): Collection
    {
        $derived = $this->resolver->sqlExpression('c.resolution_state', 'f.status');
        $statuses = [CitationStatus::Unreliable, CitationStatus::Unresolved, CitationStatus::Hallucination];
        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));

        return ResearchedDocumentCitation::query()
            ->from('researched_document_citations as c')
            ->leftJoin('researched_document_references as r', 'r.id', '=', 'c.researched_document_reference_id')
            ->leftJoin('reference_findings as f', 'f.researched_document_reference_id', '=', 'r.id')
            ->where('c.researched_document_id', $document->getKey())
            ->whereRaw("({$derived}) IN ({$placeholders})", array_map(
                static fn (CitationStatus $status): string => $status->value,
                $statuses,
            ))
            ->select('c.*')
            ->with('reference.finding')
            ->orderByRaw('c.text_start_offset IS NULL')
            ->orderBy('c.text_start_offset')
            ->orderBy('c.id')
            ->get();
    }
}
