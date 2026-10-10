<?php

namespace App\Services\Reports;

use App\Data\ResearchedDocument\DocumentAnalysisSummaryData;
use Carbon\CarbonImmutable;

/**
 * The read model rendered into a report PDF.
 *
 * Internal view model — **not** an API DTO. It is built once by
 * {@see ReportDataBuilder} and consumed by the Blade template only.
 */
final readonly class ReportPayload
{
    /**
     * @param  list<ReportReferenceRow>  $references
     * @param  list<ReportCitationRow>  $citationIssues
     */
    public function __construct(
        public string $documentName,
        public CarbonImmutable $documentCreatedAt,
        public ?CarbonImmutable $analysisCompletedAt,
        public DocumentAnalysisSummaryData $summary,
        public array $references,
        public array $citationIssues,
        public CarbonImmutable $generatedAt,
    ) {}
}
