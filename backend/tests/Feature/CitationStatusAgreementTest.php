<?php

use App\Enums\CitationResolutionState;
use App\Enums\CitationStatus;
use App\Models\ReferenceFinding;
use App\Models\ResearchedDocumentCitation;
use App\Services\Citation\CitationQueryService;
use App\Services\Citations\CitationStatusResolver;
use App\Services\Document\DocumentSummaryService;
use Tests\Support\DocumentTree;

/**
 * F-DERIV-01: the SQL scope, the PHP resolver and the summary aggregates must
 * agree on every derived citation status for the same fixture.
 */
it('agrees between the php resolver and the sql scope for every status', function () {
    $tree = DocumentTree::create();

    $validReference = $tree->reference(['text_start_offset' => 100, 'text_end_offset' => 150]);
    ReferenceFinding::factory()->forReference($validReference)->valid()->create();

    $invalidReference = $tree->reference(['text_start_offset' => 200, 'text_end_offset' => 250]);
    ReferenceFinding::factory()->forReference($invalidReference)->invalid()->create();

    $pendingReference = $tree->reference(['text_start_offset' => 300, 'text_end_offset' => 350]);
    ReferenceFinding::factory()->forReference($pendingReference)->pending()->create();

    $citations = [
        CitationStatus::Valid->value => $tree->citation($validReference, [
            'citation_text' => '(Valid, 2020)',
            'text_start_offset' => 100,
            'text_end_offset' => 110,
        ])->getKey(),
        CitationStatus::Unreliable->value => $tree->citation($invalidReference, [
            'citation_text' => '(Invalid, 2020)',
            'text_start_offset' => 200,
            'text_end_offset' => 210,
        ])->getKey(),
        CitationStatus::Pending->value => $tree->citation($pendingReference, [
            'citation_text' => '(Pending, 2020)',
            'text_start_offset' => 300,
            'text_end_offset' => 310,
        ])->getKey(),
        CitationStatus::Unresolved->value => $tree->citation(null, [
            'citation_text' => '(Kandidat, 2020)',
            'resolution_state' => CitationResolutionState::Unresolved,
            'text_start_offset' => 350,
            'text_end_offset' => 360,
        ])->getKey(),
        CitationStatus::Hallucination->value => $tree->citation(null, [
            'citation_text' => '(Halu, 2020)',
            'text_start_offset' => 400,
            'text_end_offset' => 410,
        ])->getKey(),
    ];

    $resolver = app(CitationStatusResolver::class);
    $queryService = app(CitationQueryService::class);

    $phpStatuses = ResearchedDocumentCitation::query()
        ->where('researched_document_id', $tree->document->getKey())
        ->with('reference.finding')
        ->get()
        ->mapWithKeys(fn (ResearchedDocumentCitation $citation): array => [
            $citation->getKey() => $resolver->resolve(
                $citation->resolution_state,
                $citation->reference?->finding?->status,
            ),
        ]);

    foreach (CitationStatus::cases() as $status) {
        $expected = $phpStatuses
            ->filter(fn (CitationStatus $derived): bool => $derived === $status)
            ->keys()
            ->all();

        $actual = collect($queryService->paginate($tree->document, $status)->items())
            ->pluck('id')
            ->all();

        expect($actual)->toEqualCanonicalizing($expected)
            ->and($expected)->toBe([$citations[$status->value]]);
    }

    // The grouped aggregate summary uses the same SQL expression.
    $summary = app(DocumentSummaryService::class)->countsFor($tree->document);

    expect($summary->totalCitations)->toBe(5)
        ->and($summary->validCitations)->toBe(1)
        ->and($summary->unreliableCitations)->toBe(1)
        ->and($summary->pendingCitations)->toBe(1)
        ->and($summary->unresolvedCitations)->toBe(1)
        ->and($summary->hallucinationCitations)->toBe(1);
});
