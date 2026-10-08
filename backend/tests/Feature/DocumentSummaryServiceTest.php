<?php

use App\Data\ResearchedDocument\DocumentAnalysisSummaryData;
use App\Enums\CitationStatus;
use App\Enums\DocumentStatus;
use App\Enums\ReferenceFindingStatus;
use App\Services\Citations\CitationStatusResolver;
use App\Services\Document\DocumentSummaryService;
use Illuminate\Support\Facades\DB;
use Tests\Support\DocumentTree;

beforeEach(function () {
    $this->service = app(DocumentSummaryService::class);
    $this->resolver = app(CitationStatusResolver::class);
});

it('computes exact derived counts for a completed document', function () {
    $tree = buildDocumentSummaryFixture();

    $summary = $this->service->forDocument($tree->document);

    expect($summary)->toBeInstanceOf(DocumentAnalysisSummaryData::class)
        ->and($summary->totalReferences)->toBe(5)
        ->and($summary->valid)->toBe(1)
        ->and($summary->suspicious)->toBe(1)
        ->and($summary->invalid)->toBe(1)
        ->and($summary->notFound)->toBe(1)
        ->and($summary->totalCitations)->toBe(5)
        ->and($summary->validCitations)->toBe(2)
        ->and($summary->unreliableCitations)->toBe(1)
        ->and($summary->pendingCitations)->toBe(1)
        ->and($summary->hallucinationCitations)->toBe(1);
});

it('returns null for a non-completed document', function () {
    $documents = [
        DocumentTree::create()->document,
        DocumentTree::create(attributes: ['status' => DocumentStatus::Processing])->document,
        DocumentTree::create(attributes: ['status' => DocumentStatus::Failed])->document,
    ];

    foreach ($documents as $document) {
        expect($this->service->forDocument($document))->toBeNull();
    }
});

it('batches summaries in a constant number of queries regardless of count', function () {
    $trees = collect(range(1, 5))->map(fn (): DocumentTree => buildDocumentSummaryFixture());

    DB::flushQueryLog();
    DB::enableQueryLog();

    $summaries = $this->service->forDocuments($trees->map(fn (DocumentTree $tree) => $tree->document)->all());

    expect($summaries)->toHaveCount(5)
        ->and(count(DB::getQueryLog()))->toBe(3);
});

it('excludes non-completed documents from a batch', function () {
    $completed = buildDocumentSummaryFixture();
    $pending = DocumentTree::create();

    $summaries = $this->service->forDocuments([$completed->document, $pending->document]);

    expect($summaries)->toHaveKey($completed->document->getKey())
        ->and($summaries)->not->toHaveKey($pending->document->getKey());
});

it('returns an empty array without querying for no documents', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($this->service->forDocuments([]))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('matches the SQL derivation to the PHP derivation', function () {
    $tree = buildDocumentSummaryFixture();

    $derived = $this->resolver->sqlExpression('c.researched_document_reference_id', 'f.status');

    $sql = DB::table('researched_document_citations as c')
        ->leftJoin('researched_document_references as r', 'r.id', '=', 'c.researched_document_reference_id')
        ->leftJoin('reference_findings as f', 'f.researched_document_reference_id', '=', 'r.id')
        ->where('c.researched_document_id', $tree->document->getKey())
        ->select('c.id')
        ->selectRaw("{$derived} as derived_status")
        ->pluck('derived_status', 'id')
        ->all();

    $php = $tree->document->citations()
        ->with('reference.finding')
        ->get()
        ->mapWithKeys(function ($citation): array {
            $status = CitationStatus::derive(
                $citation->researched_document_reference_id !== null,
                $citation->reference?->finding?->status,
            );

            return [$citation->id => $status->value];
        })
        ->all();

    ksort($sql);
    ksort($php);

    expect($sql)->toBe($php);
});

/**
 * One completed document: four bucketed references + one un-evaluated reference,
 * and one citation per derived status.
 */
function buildDocumentSummaryFixture(): DocumentTree
{
    $tree = DocumentTree::create();
    $tree->complete();

    $valid = $tree->reference();
    $suspicious = $tree->reference();
    $invalid = $tree->reference();
    $notFound = $tree->reference();
    $noFinding = $tree->reference();

    $tree->finding($valid, ['status' => ReferenceFindingStatus::Valid]);
    $tree->finding($suspicious, ['status' => ReferenceFindingStatus::Suspicious]);
    $tree->finding($invalid, ['status' => ReferenceFindingStatus::Invalid]);
    $tree->finding($notFound, ['status' => ReferenceFindingStatus::NotFound]);

    $tree->citation($valid);          // valid
    $tree->citation($suspicious);     // valid
    $tree->citation($invalid);        // unreliable
    $tree->citation($noFinding);      // pending
    $tree->citation(null);            // hallucination

    return $tree;
}
