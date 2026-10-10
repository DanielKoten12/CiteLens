<?php

use App\Enums\CitationResolutionState;
use App\Enums\CitationStatus;
use App\Enums\ReferenceFindingStatus;
use App\Services\Citations\CitationStatusResolver;

it('delegates every resolution state and finding status to CitationStatus::derive', function () {
    $resolver = new CitationStatusResolver;

    foreach (CitationResolutionState::cases() as $state) {
        foreach ([null, ...ReferenceFindingStatus::cases()] as $status) {
            expect($resolver->resolve($state, $status))->toBe(CitationStatus::derive($state, $status));
        }
    }
});

it('derives the five canonical buckets', function () {
    $resolver = new CitationStatusResolver;

    expect($resolver->resolve(CitationResolutionState::Unmatched, null))->toBe(CitationStatus::Hallucination)
        ->and($resolver->resolve(CitationResolutionState::Unresolved, null))->toBe(CitationStatus::Unresolved)
        ->and($resolver->resolve(CitationResolutionState::Paired, null))->toBe(CitationStatus::Pending)
        ->and($resolver->resolve(CitationResolutionState::Paired, ReferenceFindingStatus::Pending))->toBe(CitationStatus::Pending)
        ->and($resolver->resolve(CitationResolutionState::Paired, ReferenceFindingStatus::Valid))->toBe(CitationStatus::Valid)
        ->and($resolver->resolve(CitationResolutionState::Paired, ReferenceFindingStatus::Suspicious))->toBe(CitationStatus::Valid)
        ->and($resolver->resolve(CitationResolutionState::Paired, ReferenceFindingStatus::Invalid))->toBe(CitationStatus::Unreliable)
        ->and($resolver->resolve(CitationResolutionState::Paired, ReferenceFindingStatus::NotFound))->toBe(CitationStatus::Unreliable);
});

it('rejects unsafe sql identifiers', function () {
    $resolver = new CitationStatusResolver;

    expect(fn () => $resolver->sqlExpression('c.id; drop table users', 'f.status'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $resolver->sqlExpression('c.resolution_state', 'f.status or 1=1'))
        ->toThrow(InvalidArgumentException::class);
});

it('builds a case expression from the state and finding columns', function () {
    $sql = (new CitationStatusResolver)->sqlExpression('c.resolution_state', 'f.status');

    expect($sql)->toContain("WHEN c.resolution_state = 'unresolved' THEN 'unresolved'")
        ->and($sql)->toContain("WHEN c.resolution_state = 'unmatched' THEN 'hallucination'")
        ->and($sql)->toContain("THEN 'pending'")
        ->and($sql)->toContain("THEN 'valid'")
        ->and($sql)->toContain("ELSE 'unreliable'");
});
