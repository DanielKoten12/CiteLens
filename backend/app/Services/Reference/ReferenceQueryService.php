<?php

namespace App\Services\Reference;

use App\Enums\ReferenceFindingStatus;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentReference;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read queries for the document bibliography (`GET /documents/{document}/references`,
 * `docs/API_SPEC.md` §5).
 *
 * Ordering is document position (`text_start_offset` ASC, nulls last, then `id`)
 * so the list matches the bibliography and the findings feed (D-05-03/OQ-18).
 * The finding is always eager-loaded, so the DTO mapping cannot trigger N+1.
 */
final class ReferenceQueryService
{
    /**
     * @return LengthAwarePaginator<int, ResearchedDocumentReference>
     */
    public function paginate(
        ResearchedDocument $document,
        ?ReferenceFindingStatus $status = null,
        ?bool $hasDoi = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $document->references()
            ->with('finding')
            ->when($status !== null, fn (Builder $query): Builder => $this->filterStatus($query, $status))
            ->when($hasDoi !== null, fn (Builder $query): Builder => $this->filterDoi($query, $hasDoi))
            ->tap(fn (Builder $query): Builder => $this->ordered($query))
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Every reference of the document in reading order (report builder; no pagination).
     *
     * @return Collection<int, ResearchedDocumentReference>
     */
    public function all(ResearchedDocument $document): Collection
    {
        return $this->ordered($document->references()->getQuery()->with('finding'))->get();
    }

    /**
     * Document-position ordering (`text_start_offset` ASC, nulls last, then `id`)
     * shared by the paginated list and the unpaginated report read (D-05-03).
     *
     * @param  Builder<ResearchedDocumentReference>  $query
     * @return Builder<ResearchedDocumentReference>
     */
    private function ordered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('text_start_offset IS NULL')
            ->orderBy('text_start_offset')
            ->orderBy('id');
    }

    /**
     * A reference is `pending` when its finding row is missing **or** pending
     * (an un-evaluated reference must not disappear from the filter).
     *
     * @param  Builder<ResearchedDocumentReference>  $query
     * @return Builder<ResearchedDocumentReference>
     */
    private function filterStatus(Builder $query, ReferenceFindingStatus $status): Builder
    {
        if ($status === ReferenceFindingStatus::Pending) {
            return $query->where(
                fn (Builder $inner): Builder => $inner
                    ->whereDoesntHave('finding')
                    ->orWhereHas('finding', fn (Builder $finding): Builder => $finding->where('status', $status)),
            );
        }

        return $query->whereHas(
            'finding',
            fn (Builder $finding): Builder => $finding->where('status', $status),
        );
    }

    /**
     * @param  Builder<ResearchedDocumentReference>  $query
     * @return Builder<ResearchedDocumentReference>
     */
    private function filterDoi(Builder $query, bool $hasDoi): Builder
    {
        if ($hasDoi) {
            return $query->whereNotNull('doi')->where('doi', '!=', '');
        }

        return $query->where(fn (Builder $inner): Builder => $inner->whereNull('doi')->orWhere('doi', ''));
    }
}
