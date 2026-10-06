<?php

use App\Exceptions\ResourceNotFoundException;
use App\Models\GeneratedDocumentReport;
use App\Models\ReferenceFinding;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use App\Models\User;
use App\Services\Ownership\OwnedResourceFinder;

/**
 * Ownership isolation is a release blocker (`docs/SECURITY.md` §2.1):
 * foreign resources must look exactly like missing ones.
 */
beforeEach(function () {
    $this->finder = app(OwnedResourceFinder::class);
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();

    $this->document = ResearchedDocument::factory()->for($this->owner)->create();
    $this->reference = ResearchedDocumentReference::factory()->for($this->document)->create();
    $this->citation = ResearchedDocumentCitation::factory()
        ->for($this->document)
        ->pairedTo($this->reference)
        ->create();
    $this->finding = ReferenceFinding::factory()->forReference($this->reference)->valid()->create();
    $this->report = GeneratedDocumentReport::factory()->for($this->document)->create();
});

it('resolves every owned resource for its owner', function () {
    expect($this->finder->document($this->owner, $this->document->id)->is($this->document))->toBeTrue()
        ->and($this->finder->reference($this->owner, $this->reference->id)->is($this->reference))->toBeTrue()
        ->and($this->finder->citation($this->owner, $this->citation->id)->is($this->citation))->toBeTrue()
        ->and($this->finder->report($this->owner, $this->report->id)->is($this->report))->toBeTrue();
});

it('throws the per-resource 404 exception for foreign resources', function () {
    expect(fn () => $this->finder->document($this->other, $this->document->id))
        ->toThrow(ResourceNotFoundException::class, 'Dokumen tidak ditemukan.')
        ->and(fn () => $this->finder->reference($this->other, $this->reference->id))
        ->toThrow(ResourceNotFoundException::class, 'Referensi tidak ditemukan.')
        ->and(fn () => $this->finder->citation($this->other, $this->citation->id))
        ->toThrow(ResourceNotFoundException::class, 'Sitasi tidak ditemukan.')
        ->and(fn () => $this->finder->report($this->other, $this->report->id))
        ->toThrow(ResourceNotFoundException::class, 'Laporan tidak ditemukan.');
});

it('does not disclose whether a foreign resource exists', function () {
    foreach (['document', 'reference', 'citation', 'report'] as $resource) {
        $foreignId = $this->{$resource}->id;

        $foreign = captureException(fn () => $this->finder->{$resource}($this->other, $foreignId));
        $missing = captureException(fn () => $this->finder->{$resource}($this->other, fake()->uuid()));

        expect($foreign)->toBeInstanceOf(ResourceNotFoundException::class)
            ->and($missing)->toBeInstanceOf(ResourceNotFoundException::class)
            ->and($foreign->getMessage())->toBe($missing->getMessage())
            ->and($foreign->code())->toBe('NOT_FOUND')
            ->and($foreign->status())->toBe(404);
    }
});

it('scopes child queries to the owner', function () {
    User::factory()->create();

    expect(ResearchedDocumentReference::query()->forUser($this->other)->count())->toBe(0)
        ->and(ResearchedDocumentCitation::query()->forUser($this->other)->count())->toBe(0)
        ->and(ReferenceFinding::query()->forUser($this->other)->count())->toBe(0)
        ->and(GeneratedDocumentReport::query()->forUser($this->other)->count())->toBe(0)
        ->and($this->other->researchedDocuments()->count())->toBe(0);
});

function captureException(callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected the callback to throw.');
}
