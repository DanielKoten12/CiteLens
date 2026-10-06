<?php

use App\Enums\CitationStatus;
use App\Enums\FindingType;
use App\Enums\ReferenceFindingStatus;

/**
 * The four derived citation buckets (`docs/API_SPEC.md` §2.6 / `docs/TEST_PLAN.md` T-CIT-06..09).
 */
it('derives hallucination for unpaired citations', function () {
    expect(CitationStatus::derive(isPaired: false, findingStatus: null))
        ->toBe(CitationStatus::Hallucination)
        ->and(CitationStatus::derive(isPaired: false, findingStatus: ReferenceFindingStatus::Valid))
        ->toBe(CitationStatus::Hallucination);
});

it('derives pending when the reference finding is missing or pending', function () {
    expect(CitationStatus::derive(isPaired: true, findingStatus: null))
        ->toBe(CitationStatus::Pending)
        ->and(CitationStatus::derive(isPaired: true, findingStatus: ReferenceFindingStatus::Pending))
        ->toBe(CitationStatus::Pending);
});

it('derives valid when the finding is valid or suspicious', function () {
    expect(CitationStatus::derive(isPaired: true, findingStatus: ReferenceFindingStatus::Valid))
        ->toBe(CitationStatus::Valid)
        ->and(CitationStatus::derive(isPaired: true, findingStatus: ReferenceFindingStatus::Suspicious))
        ->toBe(CitationStatus::Valid);
});

it('derives unreliable when the finding is invalid or not found', function () {
    expect(CitationStatus::derive(isPaired: true, findingStatus: ReferenceFindingStatus::Invalid))
        ->toBe(CitationStatus::Unreliable)
        ->and(CitationStatus::derive(isPaired: true, findingStatus: ReferenceFindingStatus::NotFound))
        ->toBe(CitationStatus::Unreliable);
});

it('maps only unreliable and hallucination citations to finding types', function () {
    expect(CitationStatus::Valid->toFindingType())->toBeNull()
        ->and(CitationStatus::Pending->toFindingType())->toBeNull()
        ->and(CitationStatus::Unreliable->toFindingType())->toBe(FindingType::CitationUnreliable)
        ->and(CitationStatus::Hallucination->toFindingType())->toBe(FindingType::CitationHallucination);
});
