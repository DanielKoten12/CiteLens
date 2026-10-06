<?php

use App\Enums\AnalysisStep;
use App\Enums\CitationStatus;
use App\Enums\DocumentStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingType;
use App\Enums\ReferenceFindingStatus;
use App\Enums\ReportStatus;

/**
 * The enum value lists are the public contract (`docs/API_SPEC.md` §2.6).
 * A later edit must not silently change them.
 */
it('pins the canonical document status values', function () {
    expect(array_column(DocumentStatus::cases(), 'value'))
        ->toBe(['pending', 'processing', 'completed', 'failed']);
});

it('pins the canonical analysis step values and order', function () {
    expect(array_column(AnalysisStep::cases(), 'value'))
        ->toBe([
            'queued',
            'extracting',
            'persisting',
            'crossref_validation',
            'embedding',
            'scoring',
            'resolving_citations',
            'generating_report',
            'completed',
        ])
        ->and(AnalysisStep::ordered())->toBe(AnalysisStep::cases());
});

it('pins the canonical reference finding status values', function () {
    expect(array_column(ReferenceFindingStatus::cases(), 'value'))
        ->toBe(['pending', 'valid', 'suspicious', 'invalid', 'not_found']);
});

it('pins the canonical citation status values', function () {
    expect(array_column(CitationStatus::cases(), 'value'))
        ->toBe(['valid', 'unreliable', 'pending', 'hallucination']);
});

it('pins the canonical report status values', function () {
    expect(array_column(ReportStatus::cases(), 'value'))
        ->toBe(['pending', 'processing', 'completed', 'failed']);
});

it('pins the canonical finding type values', function () {
    expect(array_column(FindingType::cases(), 'value'))
        ->toBe([
            'reference_invalid',
            'reference_suspicious',
            'reference_not_found',
            'reference_pending',
            'citation_unreliable',
            'citation_hallucination',
        ]);
});

it('pins the canonical finding severity values', function () {
    expect(array_column(FindingSeverity::cases(), 'value'))
        ->toBe(['high', 'medium', 'low', 'info']);
});

it('reports terminal document and report states', function () {
    expect(DocumentStatus::Completed->isTerminal())->toBeTrue()
        ->and(DocumentStatus::Failed->isTerminal())->toBeTrue()
        ->and(DocumentStatus::Pending->isTerminal())->toBeFalse()
        ->and(DocumentStatus::Processing->isTerminal())->toBeFalse()
        ->and(ReportStatus::Completed->isTerminal())->toBeTrue()
        ->and(ReportStatus::Failed->isTerminal())->toBeTrue()
        ->and(ReportStatus::Pending->isTerminal())->toBeFalse()
        ->and(ReportStatus::Processing->isTerminal())->toBeFalse();
});

it('only allows retry for failed documents', function () {
    expect(DocumentStatus::Failed->allowsRetry())->toBeTrue()
        ->and(DocumentStatus::Pending->allowsRetry())->toBeFalse()
        ->and(DocumentStatus::Processing->allowsRetry())->toBeFalse()
        ->and(DocumentStatus::Completed->allowsRetry())->toBeFalse();
});

it('maps reference finding statuses to finding types', function () {
    expect(ReferenceFindingStatus::Valid->toFindingType())->toBeNull()
        ->and(ReferenceFindingStatus::Pending->toFindingType())->toBe(FindingType::ReferencePending)
        ->and(ReferenceFindingStatus::Suspicious->toFindingType())->toBe(FindingType::ReferenceSuspicious)
        ->and(ReferenceFindingStatus::Invalid->toFindingType())->toBe(FindingType::ReferenceInvalid)
        ->and(ReferenceFindingStatus::NotFound->toFindingType())->toBe(FindingType::ReferenceNotFound);
});

it('maps reference finding statuses to severities', function () {
    expect(ReferenceFindingStatus::Valid->toSeverity())->toBeNull()
        ->and(ReferenceFindingStatus::Pending->toSeverity())->toBe(FindingSeverity::Info)
        ->and(ReferenceFindingStatus::Suspicious->toSeverity())->toBe(FindingSeverity::Medium)
        ->and(ReferenceFindingStatus::Invalid->toSeverity())->toBe(FindingSeverity::High)
        ->and(ReferenceFindingStatus::NotFound->toSeverity())->toBe(FindingSeverity::High);
});

it('flags problem reference finding statuses', function () {
    expect(ReferenceFindingStatus::Suspicious->isProblem())->toBeTrue()
        ->and(ReferenceFindingStatus::Invalid->isProblem())->toBeTrue()
        ->and(ReferenceFindingStatus::NotFound->isProblem())->toBeTrue()
        ->and(ReferenceFindingStatus::Pending->isProblem())->toBeFalse()
        ->and(ReferenceFindingStatus::Valid->isProblem())->toBeFalse();
});

it('distinguishes reference and citation finding types', function () {
    expect(FindingType::ReferenceInvalid->isReference())->toBeTrue()
        ->and(FindingType::ReferenceInvalid->isCitation())->toBeFalse()
        ->and(FindingType::CitationHallucination->isCitation())->toBeTrue()
        ->and(FindingType::CitationHallucination->isReference())->toBeFalse();
});

it('ranks severities from high to info', function () {
    expect(FindingSeverity::High->rank())->toBe(3)
        ->and(FindingSeverity::Medium->rank())->toBe(2)
        ->and(FindingSeverity::Low->rank())->toBe(1)
        ->and(FindingSeverity::Info->rank())->toBe(0);
});
