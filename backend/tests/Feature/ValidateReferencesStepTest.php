<?php

use App\Exceptions\CrossrefUnavailableException;
use App\Models\ResearchedDocumentReference;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\ReferenceVerification;
use App\Services\Analysis\Steps\ValidateReferencesStep;
use App\Services\Crossref\DoiLookup;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CrossrefFake;
use Tests\Support\DocumentTree;
use Tests\Support\Fixtures;

function verificationFor(AnalysisContext $context, string $referenceId): ReferenceVerification
{
    foreach ($context->verification()->verifications as $verification) {
        if ($verification->reference->id === $referenceId) {
            return $verification;
        }
    }

    throw new RuntimeException('No verification for reference '.$referenceId);
}

it('resolves a doi and merges search candidates', function () {
    CrossrefFake::forExtractFixture();

    $tree = DocumentTree::create();

    $valid = $tree->reference([
        'doi' => 'https://doi.org/10.1038/NATURE14539',
        'title' => 'Deep learning',
        'authors' => 'LeCun, Y.',
        'publication_year' => 2015,
    ]);

    $noDoi = $tree->reference([
        'doi' => null,
        'title' => 'Sistem deteksi plagiarisme',
        'authors' => 'Koten, D.',
    ]);

    $malformed = $tree->reference([
        'doi' => 'doi:not-a-doi',
        'title' => 'Broken reference',
        'authors' => 'Tani, B.',
    ]);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    $batch = $context->verification();

    expect($batch->count())->toBe(3);

    $validVerification = verificationFor($context, $valid->getKey());
    expect($validVerification->doiLookup)->toBe(DoiLookup::Resolved)
        ->and($validVerification->transientFailure)->toBeFalse()
        ->and($validVerification->candidates[0]->doi)->toBe('10.1038/nature14539');

    $noDoiVerification = verificationFor($context, $noDoi->getKey());
    expect($noDoiVerification->doiLookup)->toBe(DoiLookup::NotPresent)
        ->and($noDoiVerification->candidates[0]->doi)->toBe('10.1234/example');

    $malformedVerification = verificationFor($context, $malformed->getKey());
    expect($malformedVerification->doiLookup)->toBe(DoiLookup::Malformed)
        ->and($malformedVerification->candidates)->toBe([]);
});

it('classifies a doi miss as not found', function () {
    CrossrefFake::install();

    $tree = DocumentTree::create();
    $reference = $tree->reference([
        'doi' => '10.1234/missing',
        'title' => 'Unknown work',
        'authors' => 'Doe, J.',
    ]);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    expect(verificationFor($context, $reference->getKey())->doiLookup)->toBe(DoiLookup::NotFound);
});

it('uses the deterministic bibliography order', function () {
    CrossrefFake::install();

    $tree = DocumentTree::create();

    $second = $tree->reference(['text_start_offset' => 200, 'text_end_offset' => 300]);
    $first = $tree->reference(['text_start_offset' => 100, 'text_end_offset' => 150]);
    $noOffset = ResearchedDocumentReference::factory()->for($tree->document)->create([
        'text_start_offset' => null,
        'text_end_offset' => null,
    ]);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    expect(array_map(fn ($verification) => $verification->reference->id, $context->verification()->verifications))
        ->toBe([$first->getKey(), $second->getKey(), $noOffset->getKey()]);
});

it('deduplicates candidates returned by both the doi lookup and the search', function () {
    $work = Fixtures::json('crossref/doi-found')['message'];

    Http::fake([
        'api.crossref.org/*' => function (Request $request) use ($work) {
            if (str_contains(urldecode($request->url()), 'query.bibliographic')) {
                return Http::response(['status' => 'ok', 'message' => ['items' => [$work, $work]]]);
            }

            return Http::response(Fixtures::json('crossref/doi-found'));
        },
    ]);

    $tree = DocumentTree::create();
    $reference = $tree->reference([
        'doi' => '10.1038/nature14539',
        'title' => 'Deep learning',
        'authors' => 'LeCun, Y.',
    ]);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    $verification = verificationFor($context, $reference->getKey());

    expect($verification->candidates)->toHaveCount(1);
});

it('fails the step when the very first request hits a total outage', function () {
    Http::fake(['api.crossref.org/*' => fn () => throw new ConnectionException('Connection refused.')]);

    $tree = DocumentTree::create();
    $tree->reference(['doi' => '10.1038/nature14539', 'title' => 'Deep learning', 'authors' => 'LeCun, Y.']);

    expect(fn () => app(ValidateReferencesStep::class)->handle($tree->document, new AnalysisContext))
        ->toThrow(CrossrefUnavailableException::class);
});

it('degrades only the failing reference once an exchange has succeeded', function () {
    // F-XREF-03 / T-PIPE-03
    Http::fake([
        'api.crossref.org/*' => function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/works/10.1234/down')) {
                throw new ConnectionException('Connection refused.');
            }

            if (str_contains(urldecode($url), 'query.bibliographic')) {
                return Http::response(Fixtures::json('crossref/search-empty'));
            }

            return Http::response(Fixtures::json('crossref/doi-found'));
        },
    ]);

    $tree = DocumentTree::create();

    $healthy = $tree->reference(['doi' => '10.1234/ok', 'title' => 'Deep learning', 'authors' => 'LeCun, Y.']);
    $failing = $tree->reference(['doi' => '10.1234/down', 'title' => 'Another work', 'authors' => 'Doe, J.']);
    $after = $tree->reference(['doi' => '10.1234/ok', 'title' => 'Third work', 'authors' => 'Roe, R.']);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    expect(verificationFor($context, $healthy->getKey())->transientFailure)->toBeFalse()
        ->and(verificationFor($context, $failing->getKey())->transientFailure)->toBeTrue()
        ->and(verificationFor($context, $after->getKey())->transientFailure)->toBeFalse();
});

it('treats a 404 as reachability before a later transport failure', function () {
    Http::fake([
        'api.crossref.org/*' => function (Request $request) {
            if (str_contains($request->url(), '/works/10.1234/down')) {
                throw new ConnectionException('Connection refused.');
            }

            if (str_contains(urldecode($request->url()), 'query.bibliographic')) {
                return Http::response(Fixtures::json('crossref/search-empty'));
            }

            return Http::response('', 404);
        },
    ]);

    $tree = DocumentTree::create();

    $missing = $tree->reference(['doi' => '10.1234/missing', 'title' => 'Missing work', 'authors' => 'Doe, J.']);
    $failing = $tree->reference(['doi' => '10.1234/down', 'title' => 'Another work', 'authors' => 'Roe, R.']);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    expect(verificationFor($context, $missing->getKey())->doiLookup)->toBe(DoiLookup::NotFound)
        ->and(verificationFor($context, $failing->getKey())->transientFailure)->toBeTrue();
});

it('reports progress across the crossref validation range', function () {
    CrossrefFake::install();

    $tree = DocumentTree::create();
    $tree->reference(['text_start_offset' => 0]);
    $tree->reference(['text_start_offset' => 100]);

    $document = $tree->document;

    app(ValidateReferencesStep::class)->handle($document, new AnalysisContext);

    expect($document->refresh()->analysis_progress)->toBeGreaterThanOrEqual(35)
        ->and($document->analysis_progress)->toBeLessThanOrEqual(60);
});
