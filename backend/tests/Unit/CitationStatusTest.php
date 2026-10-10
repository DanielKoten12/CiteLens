<?php

use App\Enums\CitationResolutionState;
use App\Enums\CitationStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingType;
use App\Enums\ReferenceFindingStatus;

/**
 * The five derived citation buckets (`docs/API_SPEC.md` §2.6 / `docs/TEST_PLAN.md` T-CIT-06..09).
 */
it('derives unresolved and hallucination from the resolution state', function () {
    expect(CitationStatus::derive(CitationResolutionState::Unresolved, null))
        ->toBe(CitationStatus::Unresolved)
        ->and(CitationStatus::derive(CitationResolutionState::Unresolved, ReferenceFindingStatus::Valid))
        ->toBe(CitationStatus::Unresolved)
        ->and(CitationStatus::derive(CitationResolutionState::Unmatched, null))
        ->toBe(CitationStatus::Hallucination)
        ->and(CitationStatus::derive(CitationResolutionState::Unmatched, ReferenceFindingStatus::Valid))
        ->toBe(CitationStatus::Hallucination);
});

it('derives pending when the reference finding is missing or pending', function () {
    expect(CitationStatus::derive(CitationResolutionState::Paired, null))
        ->toBe(CitationStatus::Pending)
        ->and(CitationStatus::derive(CitationResolutionState::Paired, ReferenceFindingStatus::Pending))
        ->toBe(CitationStatus::Pending);
});

it('derives valid when the finding is valid or suspicious', function () {
    expect(CitationStatus::derive(CitationResolutionState::Paired, ReferenceFindingStatus::Valid))
        ->toBe(CitationStatus::Valid)
        ->and(CitationStatus::derive(CitationResolutionState::Paired, ReferenceFindingStatus::Suspicious))
        ->toBe(CitationStatus::Valid);
});

it('derives unreliable when the finding is invalid or not found', function () {
    expect(CitationStatus::derive(CitationResolutionState::Paired, ReferenceFindingStatus::Invalid))
        ->toBe(CitationStatus::Unreliable)
        ->and(CitationStatus::derive(CitationResolutionState::Paired, ReferenceFindingStatus::NotFound))
        ->toBe(CitationStatus::Unreliable);
});

it('maps only problem citations to finding types', function () {
    expect(CitationStatus::Valid->toFindingType())->toBeNull()
        ->and(CitationStatus::Pending->toFindingType())->toBeNull()
        ->and(CitationStatus::Unreliable->toFindingType())->toBe(FindingType::CitationUnreliable)
        ->and(CitationStatus::Unresolved->toFindingType())->toBe(FindingType::CitationUnresolved)
        ->and(CitationStatus::Hallucination->toFindingType())->toBe(FindingType::CitationHallucination);
});

it('maps citation statuses to finding severities', function () {
    expect(CitationStatus::Unresolved->toSeverity())->toBe(FindingSeverity::Medium)
        ->and(CitationStatus::Hallucination->toSeverity())->toBe(FindingSeverity::High)
        ->and(CitationStatus::Unreliable->toSeverity(ReferenceFindingStatus::Invalid))->toBe(FindingSeverity::High)
        ->and(CitationStatus::Unreliable->toSeverity(ReferenceFindingStatus::NotFound))->toBe(FindingSeverity::High)
        ->and(CitationStatus::Valid->toSeverity(ReferenceFindingStatus::Valid))->toBeNull()
        ->and(CitationStatus::Pending->toSeverity(null))->toBeNull();
});

it('returns the human-facing message only for issue statuses', function () {
    expect(CitationStatus::Valid->message())->toBeNull()
        ->and(CitationStatus::Pending->message())->toBeNull()
        ->and(CitationStatus::Unreliable->message())
        ->toBe('Sitasi merujuk pada referensi yang tidak berhasil diverifikasi (invalid/not_found).')
        ->and(CitationStatus::Unresolved->message())
        ->toBe('Sitasi belum dapat ditautkan secara pasti ke referensi.')
        ->and(CitationStatus::Hallucination->message())
        ->toBe('Sitasi tidak memiliki pasangan referensi (hallucination).');
});
