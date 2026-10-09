<?php

use App\Enums\DocumentStatus;
use App\Enums\ReferenceFindingStatus;
use App\Exceptions\StateConflictException;
use App\Models\User;
use App\Services\ReferenceFinding\ReferenceFindingReviewService;
use Illuminate\Validation\ValidationException;
use Tests\Support\DocumentTree;

function reviewService(): ReferenceFindingReviewService
{
    return app(ReferenceFindingReviewService::class);
}

it('updates an existing finding and audits the reviewer', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();
    $finding = $tree->finding($reference, [
        'status' => ReferenceFindingStatus::Suspicious,
        'confidence' => 0.63,
    ], 1);
    $reviewer = User::factory()->create();

    $reviewed = reviewService()->review(
        $reference,
        ReferenceFindingStatus::Valid,
        $finding->selected_candidate_id,
        'Diverifikasi manual.',
        $reviewer,
    );

    expect($reviewed->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($reviewed->is_manual)->toBeTrue()
        ->and($reviewed->reviewed_by)->toBe($reviewer->getKey())
        ->and($reviewed->reviewed_at)->not->toBeNull()
        ->and($reviewed->confidence)->toBe(0.63)
        ->and($reviewed->selected_candidate_id)->toBe($finding->selected_candidate_id)
        ->and($reviewed->reason)->toBe('Diverifikasi manual.');
});

it('creates a manual finding when none exists', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();
    $reviewer = User::factory()->create();

    $reviewed = reviewService()->review(
        $reference,
        ReferenceFindingStatus::NotFound,
        null,
        null,
        $reviewer,
    );

    expect($reviewed->exists)->toBeTrue()
        ->and($reviewed->researched_document_reference_id)->toBe($reference->getKey())
        ->and($reviewed->confidence)->toBeNull()
        ->and($reviewed->is_manual)->toBeTrue()
        ->and($reviewed->reviewed_by)->toBe($reviewer->getKey());
});

it('rejects a candidate that belongs to another finding', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference(['text_start_offset' => 100, 'text_end_offset' => 150]);
    $tree->finding($reference, ['status' => ReferenceFindingStatus::Suspicious], 1);

    $otherReference = $tree->reference(['text_start_offset' => 200, 'text_end_offset' => 250]);
    $otherFinding = $tree->finding($otherReference, ['status' => ReferenceFindingStatus::Suspicious], 1);
    $reviewer = User::factory()->create();

    expect(fn () => reviewService()->review(
        $reference,
        ReferenceFindingStatus::Valid,
        $otherFinding->selected_candidate_id,
        null,
        $reviewer,
    ))->toThrow(ValidationException::class);

    expect($reference->fresh()->finding->is_manual)->toBeFalse();
});

it('rejects reviewing a reference while the document is processing', function () {
    $tree = DocumentTree::create(attributes: ['status' => DocumentStatus::Processing]);
    $reference = $tree->reference();
    $tree->finding($reference, ['status' => ReferenceFindingStatus::Suspicious], 1);
    $reviewer = User::factory()->create();

    expect(fn () => reviewService()->review(
        $reference,
        ReferenceFindingStatus::Valid,
        null,
        null,
        $reviewer,
    ))->toThrow(StateConflictException::class, 'Status referensi tidak dapat diubah saat analisis sedang berjalan.');
});

it('rejects a candidate id that does not exist', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();
    $reviewer = User::factory()->create();

    expect(fn () => reviewService()->review(
        $reference,
        ReferenceFindingStatus::Valid,
        fake()->uuid(),
        null,
        $reviewer,
    ))->toThrow(ValidationException::class);
});
