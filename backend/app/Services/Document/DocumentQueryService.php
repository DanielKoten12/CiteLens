<?php

namespace App\Services\Document;

use App\Enums\DocumentStatus;
use App\Models\ResearchedDocument;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read queries for the document history (`GET /documents`).
 *
 * Kept free of HTTP concerns: the controller passes already-validated primitives
 * so the service is trivially testable and reusable.
 */
final class DocumentQueryService
{
    private const string DEFAULT_SORT = '-created_at';

    /**
     * Paginate the authenticated user's documents.
     *
     * Ordering is deterministic: the requested `created_at` direction followed by
     * the `id` tiebreaker, so pagination cannot skip or duplicate rows.
     */
    public function paginate(
        User $user,
        ?DocumentStatus $status = null,
        ?string $search = null,
        string $sort = self::DEFAULT_SORT,
        int $perPage = 15,
    ): LengthAwarePaginator {
        [$column, $direction] = $this->resolveSort($sort);

        return ResearchedDocument::query()
            ->forUser($user)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($search !== null, fn ($query) => $this->applySearch($query, $search))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSort(string $sort): array
    {
        if (! in_array($sort, ['created_at', '-created_at'], true)) {
            $sort = self::DEFAULT_SORT;
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        return ['created_at', $direction];
    }

    /**
     * Case-insensitive name search with `%`/`_` wildcards escaped, so user input
     * can never broaden the match (`ESCAPE` is portable across SQLite/MySQL/Postgres).
     */
    private function applySearch(mixed $query, string $search): void
    {
        $pattern = '%'.addcslashes($search, '%_\\').'%';

        $query->whereRaw("name LIKE ? ESCAPE '\\'", [$pattern]);
    }
}
