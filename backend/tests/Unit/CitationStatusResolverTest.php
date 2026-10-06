<?php

use App\Enums\CitationStatus;
use App\Enums\ReferenceFindingStatus;
use App\Services\Citations\CitationStatusResolver;

it('delegates every pairing and finding status to CitationStatus::derive', function () {
    $resolver = new CitationStatusResolver;

    foreach ([true, false] as $paired) {
        foreach ([null, ...ReferenceFindingStatus::cases()] as $status) {
            expect($resolver->resolve($paired, $status))->toBe(CitationStatus::derive($paired, $status));
        }
    }
});

it('derives the four canonical buckets', function () {
    $resolver = new CitationStatusResolver;

    expect($resolver->resolve(false, null))->toBe(CitationStatus::Hallucination)
        ->and($resolver->resolve(true, null))->toBe(CitationStatus::Pending)
        ->and($resolver->resolve(true, ReferenceFindingStatus::Pending))->toBe(CitationStatus::Pending)
        ->and($resolver->resolve(true, ReferenceFindingStatus::Valid))->toBe(CitationStatus::Valid)
        ->and($resolver->resolve(true, ReferenceFindingStatus::Suspicious))->toBe(CitationStatus::Valid)
        ->and($resolver->resolve(true, ReferenceFindingStatus::Invalid))->toBe(CitationStatus::Unreliable)
        ->and($resolver->resolve(true, ReferenceFindingStatus::NotFound))->toBe(CitationStatus::Unreliable);
});

it('rejects unsafe sql identifiers', function () {
    $resolver = new CitationStatusResolver;

    expect(fn () => $resolver->sqlExpression('c.id; drop table users', 'f.status'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $resolver->sqlExpression('c.researched_document_reference_id', 'f.status or 1=1'))
        ->toThrow(InvalidArgumentException::class);
});
