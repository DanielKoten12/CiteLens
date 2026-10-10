<?php

namespace App\Services\Findings;

use App\Data\Finding\FindingHighlightData;
use App\Data\Location\LocationPreviewData;
use App\Models\ResearchedDocumentCitationLocation;
use App\Models\ResearchedDocumentReferenceLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Turns a page of normalized feed rows into DTOs, batch-loading the highlight
 * locations so the feed page costs a constant number of queries.
 */
final class FindingsFeedComposer
{
    /**
     * @param  LengthAwarePaginator<int, object>  $paginator
     * @return LengthAwarePaginator<int, FindingHighlightData>
     */
    public function compose(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $referenceIds = [];
        $citationIds = [];

        foreach ($paginator->items() as $row) {
            if ($row->source === 'reference' && $row->reference_id !== null) {
                $referenceIds[] = $row->reference_id;
            } elseif ($row->source === 'citation' && $row->citation_id !== null) {
                $citationIds[] = $row->citation_id;
            }
        }

        $referenceLocations = $this->referenceLocations($referenceIds);
        $citationLocations = $this->citationLocations($citationIds);

        $paginator->through(function (object $row) use ($referenceLocations, $citationLocations): FindingHighlightData {
            $locations = $row->source === 'reference'
                ? ($referenceLocations[$row->reference_id] ?? [])
                : ($citationLocations[$row->citation_id] ?? []);

            return FindingHighlightData::fromFeedRow($row, $locations);
        });

        return $paginator;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<LocationPreviewData>>
     */
    private function referenceLocations(array $ids): array
    {
        return $this->groupedLocations(
            ResearchedDocumentReferenceLocation::query()
                ->whereIn('researched_document_reference_id', $ids)
                ->orderBy('location_index')
                ->get(),
            'researched_document_reference_id',
        );
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<LocationPreviewData>>
     */
    private function citationLocations(array $ids): array
    {
        return $this->groupedLocations(
            ResearchedDocumentCitationLocation::query()
                ->whereIn('citation_id', $ids)
                ->orderBy('location_index')
                ->get(),
            'citation_id',
        );
    }

    /**
     * @param  Collection<int, ResearchedDocumentReferenceLocation|ResearchedDocumentCitationLocation>  $locations
     * @return array<string, list<LocationPreviewData>>
     */
    private function groupedLocations(Collection $locations, string $parentKey): array
    {
        if ($locations->isEmpty()) {
            return [];
        }

        return $locations
            ->groupBy($parentKey)
            ->map(fn (Collection $group): array => $group
                ->map(static fn ($location): LocationPreviewData => LocationPreviewData::fromModel($location))
                ->values()
                ->all())
            ->all();
    }
}
