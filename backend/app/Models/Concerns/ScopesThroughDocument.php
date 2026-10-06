<?php

namespace App\Models\Concerns;

use App\Models\ResearchedDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ownership scoping for models whose `researched_document_id` column links them
 * to their owning document.
 *
 * The canonical pattern is `Model::query()->forUser($user)->find($id)` wrapped by
 * `OwnedResourceFinder`, which throws the per-resource 404 exception.
 */
trait ScopesThroughDocument
{
    /**
     * The owning research document.
     *
     * @return BelongsTo<ResearchedDocument, $this>
     */
    public function researchedDocument(): BelongsTo
    {
        return $this->belongsTo(ResearchedDocument::class, 'researched_document_id');
    }

    /**
     * Scope to rows whose parent document belongs to the given user.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'researchedDocument',
            fn (Builder $documentQuery): Builder => $documentQuery->where('user_id', $user->getKey()),
        );
    }

    /**
     * Scope to rows of a specific document.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForDocument(Builder $query, ResearchedDocument $document): Builder
    {
        return $query->where($query->qualifyColumn('researched_document_id'), $document->getKey());
    }
}
