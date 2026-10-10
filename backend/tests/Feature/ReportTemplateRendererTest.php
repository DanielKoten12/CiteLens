<?php

use App\Data\ResearchedDocument\DocumentAnalysisSummaryData;
use App\Enums\CitationStatus;
use App\Enums\ReferenceFindingStatus;
use App\Services\Reports\ReportCitationRow;
use App\Services\Reports\ReportPayload;
use App\Services\Reports\ReportReferenceRow;
use App\Services\Reports\ReportTemplateRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function samplePayload(): ReportPayload
{
    return new ReportPayload(
        documentName: '<script>alert(1)</script>',
        documentCreatedAt: CarbonImmutable::parse('2026-01-02 03:04:05'),
        analysisCompletedAt: null,
        summary: DocumentAnalysisSummaryData::forCounts(1, 0, 0, 1, 0, 1, 0, 0, 0, 0, 1),
        references: [
            new ReportReferenceRow(
                rawText: '<script>alert(2)</script>',
                doi: '10.1000/xyz',
                title: null,
                authors: 'Smith, J.',
                publicationName: 'Journal',
                publicationYear: 2020,
                status: ReferenceFindingStatus::Invalid,
                confidence: 0.5,
                reason: 'DOI tidak cocok.',
            ),
        ],
        citationIssues: [
            new ReportCitationRow(
                citationText: '(<img src=x onerror=alert(1)>)',
                status: CitationStatus::Hallucination,
                referenceLabel: null,
                message: (string) CitationStatus::Hallucination->message(),
            ),
        ],
        generatedAt: CarbonImmutable::parse('2026-01-03 03:04:05'),
    );
}

it('renders every section with escaped user text', function () {
    $html = app(ReportTemplateRenderer::class)->render(samplePayload());

    expect($html)->toContain('Laporan Pemeriksaan Referensi')
        ->and($html)->toContain('Ringkasan Analisis')
        ->and($html)->toContain('Daftar Referensi')
        ->and($html)->toContain('Masalah Sitasi')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('&lt;script&gt;alert(2)&lt;/script&gt;')
        ->and($html)->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->and($html)->not->toContain('<script>')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->toContain('50.0%')
        ->and($html)->toContain('Tidak valid')
        ->and($html)->toContain('Tidak ada referensi')
        ->and($html)->toContain('02/01/2026 03:04');
});

it('renders the empty state when there is nothing to report', function () {
    $payload = new ReportPayload(
        documentName: 'Skripsi',
        documentCreatedAt: CarbonImmutable::parse('2026-01-02 03:04:05'),
        analysisCompletedAt: CarbonImmutable::parse('2026-01-02 04:00:00'),
        summary: DocumentAnalysisSummaryData::forCounts(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
        references: [],
        citationIssues: [],
        generatedAt: CarbonImmutable::parse('2026-01-03 03:04:05'),
    );

    $html = app(ReportTemplateRenderer::class)->render($payload);

    expect($html)->toContain('Tidak ada referensi yang terdeteksi')
        ->and($html)->toContain('Tidak ada masalah sitasi yang terdeteksi')
        ->and($html)->toContain('02/01/2026 04:00');
});
