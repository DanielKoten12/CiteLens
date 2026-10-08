<?php

namespace App\Services\Analysis\Steps;

use App\Data\Inference\ExtractedCitationData;
use App\Data\Inference\ExtractedLocationData;
use App\Data\Inference\ExtractedReferenceData;
use App\Enums\AnalysisStep;
use App\Exceptions\ExtractionFailedException;
use App\Models\ResearchedDocument;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\Crossref\DoiNormalizer;
use App\Services\Document\DocumentAnalysisResetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists an extraction result into the canonical reference/citation tables
 * (`docs/API_SPEC.md` §10, `docs/DB_SCHEMA.md`).
 *
 * The whole document is written in one transaction after resetting the previous
 * run's derived rows, so the step is idempotent and a failure never leaves a
 * half-persisted document. Locations and citation occurrences are reindexed to
 * satisfy the canonical unique indexes; structural payload violations are fatal,
 * semantically implausible values are sanitized deterministically.
 *
 * Citation `researched_document_reference_id` stays `null`: pairing is Phase 05.
 */
final class PersistExtractionStep implements PipelineStep
{
    private const int INSERT_CHUNK = 500;

    private const int BBOX_PRECISION = 4;

    private const int MIN_YEAR = 1000;

    private const string COORDINATE_SYSTEM = 'pdf_points_top_left';

    public function __construct(
        private readonly DocumentAnalysisResetService $resetService,
        private readonly DoiNormalizer $doiNormalizer,
    ) {}

    public function step(): AnalysisStep
    {
        return AnalysisStep::Persisting;
    }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        $extraction = $context->takeExtraction();

        DB::transaction(function () use ($document, $extraction): void {
            $this->resetService->reset($document);

            $references = $this->referenceRows($document, $extraction->references);
            $this->insert('researched_document_references', $references['rows']);
            $this->insert('researched_document_reference_locations', $references['locations']);

            $citations = $this->citationRows($document, $extraction->citations);
            $this->insert('researched_document_citations', $citations['rows']);
            $this->insert('researched_document_citation_locations', $citations['locations']);
        });
    }

    /**
     * @param  list<ExtractedReferenceData>  $references
     * @return array{rows: list<array<string, mixed>>, locations: list<array<string, mixed>>}
     */
    private function referenceRows(ResearchedDocument $document, array $references): array
    {
        $rows = [];
        $locations = [];
        $now = now();

        foreach ($references as $reference) {
            $referenceId = (string) Str::uuid();

            [$startOffset, $endOffset] = $this->orderedOffsets($reference->textStartOffset, $reference->textEndOffset);

            $rows[] = [
                'id' => $referenceId,
                'researched_document_id' => $document->getKey(),
                'raw_text' => $this->boundedText($reference->rawText, 'raw_text'),
                'doi' => $this->doiNormalizer->normalize($reference->doi),
                'title' => $this->boundedText($reference->title, 'title'),
                'authors' => $this->boundedText($reference->authors, 'authors'),
                'publication_name' => $this->boundedText($reference->publicationName, 'publication_name'),
                'publication_year' => $this->year($reference->publicationYear),
                'text_start_offset' => $startOffset,
                'text_end_offset' => $endOffset,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($this->locationRows($reference->locations, 'researched_document_reference_id', $referenceId) as $location) {
                $locations[] = $location;
            }
        }

        return ['rows' => $rows, 'locations' => $locations];
    }

    /**
     * @param  list<ExtractedCitationData>  $citations
     * @return array{rows: list<array<string, mixed>>, locations: list<array<string, mixed>>}
     */
    private function citationRows(ResearchedDocument $document, array $citations): array
    {
        $rows = [];
        $locations = [];
        $now = now();
        $occurrenceIndexes = $this->occurrenceIndexes($citations);

        foreach ($citations as $index => $citation) {
            if (trim($citation->citationText) === '') {
                throw ExtractionFailedException::malformedPayload();
            }

            $citationId = (string) Str::uuid();

            [$startOffset, $endOffset] = $this->orderedOffsets($citation->textStartOffset, $citation->textEndOffset);

            $rows[] = [
                'id' => $citationId,
                'researched_document_id' => $document->getKey(),
                // Pairing happens in Phase 05; extraction never pairs.
                'researched_document_reference_id' => null,
                'citation_text' => $this->boundedText($citation->citationText, 'citation_text'),
                'citation_marker' => $this->boundedText($citation->citationMarker, 'citation_marker'),
                'context_before' => $this->boundedText($citation->contextBefore, 'context_before'),
                'context_after' => $this->boundedText($citation->contextAfter, 'context_after'),
                'text_start_offset' => $startOffset,
                'text_end_offset' => $endOffset,
                'occurrence_index' => $occurrenceIndexes[$index],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($this->locationRows($citation->locations, 'citation_id', $citationId) as $location) {
                $locations[] = $location;
            }
        }

        return ['rows' => $rows, 'locations' => $locations];
    }

    /**
     * @param  list<ExtractedLocationData>  $locations
     * @return list<array<string, mixed>>
     */
    private function locationRows(array $locations, string $foreignKey, string $parentId): array
    {
        $rows = [];
        $now = now();

        foreach ($locations as $index => $location) {
            if ($location->pageNumber < 1) {
                throw ExtractionFailedException::malformedPayload();
            }

            $rows[] = [
                'id' => (string) Str::uuid(),
                $foreignKey => $parentId,
                'page_number' => $location->pageNumber,
                'x' => $this->coordinate($location->x),
                'y' => $this->coordinate($location->y),
                'width' => $this->coordinate($location->width),
                'height' => $this->coordinate($location->height),
                'page_width' => $this->coordinate($location->pageWidth),
                'page_height' => $this->coordinate($location->pageHeight),
                'coordinate_system' => self::COORDINATE_SYSTEM,
                'location_index' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Assign unique `0..N-1` occurrence indexes per document.
     *
     * When every payload value is present and unique it is kept; otherwise the
     * whole set is reindexed by document position (`text_start_offset` ASC, nulls
     * last, payload order tiebreak) so the canonical unique index cannot fail and
     * the viewer order stays stable.
     *
     * @param  list<ExtractedCitationData>  $citations
     * @return list<int>
     */
    private function occurrenceIndexes(array $citations): array
    {
        $indexes = array_map(
            static fn (ExtractedCitationData $citation): ?int => $citation->occurrenceIndex,
            $citations,
        );

        if (! in_array(null, $indexes, true) && count(array_unique($indexes)) === count($citations)) {
            return array_map(static fn (int $value): int => $value, $indexes);
        }

        $order = array_keys($citations);

        usort($order, static function (int $left, int $right) use ($citations): int {
            $leftOffset = $citations[$left]->textStartOffset;
            $rightOffset = $citations[$right]->textStartOffset;

            if ($leftOffset === $rightOffset) {
                return $left <=> $right;
            }

            if ($leftOffset === null) {
                return 1;
            }

            if ($rightOffset === null) {
                return -1;
            }

            return $leftOffset <=> $rightOffset;
        });

        $assigned = [];

        foreach ($order as $rank => $payloadIndex) {
            $assigned[$payloadIndex] = $rank;
        }

        ksort($assigned);

        return array_values($assigned);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function orderedOffsets(?int $start, ?int $end): array
    {
        if ($start !== null && $end !== null && $start > $end) {
            return [$end, $start];
        }

        return [$start, $end];
    }

    /**
     * @throws ExtractionFailedException when the value cannot fit the column
     */
    private function boundedText(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) > $this->maxTextLength()) {
            throw ExtractionFailedException::payloadTooLarge($field);
        }

        return $value;
    }

    /**
     * Drop implausible years (extraction noise) instead of failing the document.
     */
    private function year(?int $year): ?int
    {
        if ($year === null || $year < self::MIN_YEAR) {
            return null;
        }

        return $year <= (int) config('analysis.extraction.max_year', 2100) ? $year : null;
    }

    private function coordinate(?float $value): ?float
    {
        return $value === null ? null : round($value, self::BBOX_PRECISION);
    }

    private function maxTextLength(): int
    {
        return max(1, (int) config('analysis.extraction.max_text_length', 65535));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
