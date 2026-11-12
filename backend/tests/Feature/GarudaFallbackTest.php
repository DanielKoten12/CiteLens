<?php

use App\Enums\MetadataSource;
use App\Exceptions\GarudaUnavailableException;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\ReferenceVerification;
use App\Services\Analysis\Steps\ValidateReferencesStep;
use App\Services\Scoring\MatchReasonBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\CrossrefFake;
use Tests\Support\DocumentTree;
use Tests\Support\Fixtures;
use Tests\Support\GarudaFake;

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.garuda.enabled' => true,
        'services.garuda.base_url' => 'https://garuda.kemdiktisaintek.go.id',
        'services.garuda.rows' => 3,
        'services.garuda.retries' => 0,
        'services.garuda.retry_backoff_ms' => 0,
        'services.garuda.min_interval_ms' => 0,
        'services.garuda.cache_ttl' => 3600,
        'services.garuda.negative_cache_ttl' => 60,
        'services.garuda.max_requests_per_document' => 500,
        'services.garuda.max_response_bytes' => 2097152,
        'services.garuda.user_agent' => null,
        'services.crossref.mailto' => 'test@example.com',
        'scoring.fallback.title_threshold' => 0.85,
    ]);
});

/**
 * @return list<ReferenceVerification>
 */
function garudaVerifications(AnalysisContext $context): array
{
    return $context->verification()->verifications;
}

function garudaCrossrefPayload(string $title): array
{
    return ['message' => ['items' => [[
        'DOI' => '10.1234/garuda-match',
        'title' => [$title],
        'author' => [['given' => 'Aveny', 'family' => 'Kurnia']],
        'container-title' => ['Jurnal Tonggak Pendidikan Dasar'],
        'issued' => ['date-parts' => [[2023]]],
        'URL' => 'https://doi.org/10.1234/garuda-match',
        'type' => 'journal-article',
    ]]]];
}

it('falls back to garuda when crossref has no candidate', function () {
    CrossrefFake::install();
    GarudaFake::results();

    $tree = DocumentTree::create();
    $reference = $tree->reference([
        'doi' => null,
        'title' => 'Literasi Digital Sebagai Upaya Menangkal Hoax di Lingkungan Masyarakat Indonesia',
        'authors' => 'Mursyida, A.',
        'publication_year' => 2023,
    ]);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    $verification = garudaVerifications($context)[0];

    expect($verification->transientFailure)->toBeFalse()
        ->and($verification->candidates)->not->toBeEmpty()
        ->and($verification->candidates[0]->source)->toBe(MetadataSource::Garuda);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'garuda.kemdiktisaintek.go.id'));
    expect($reference->getKey())->toBe($verification->reference->id);
});

it('does not consult garuda when a primary candidate is a strong title match', function () {
    $title = 'Literasi Digital Sebagai Upaya Menangkal Hoax di Lingkungan Masyarakat Indonesia';

    CrossrefFake::install(searchRoutes: ['Literasi Digital' => garudaCrossrefPayload($title)]);
    GarudaFake::results();

    $tree = DocumentTree::create();
    $tree->reference(['doi' => null, 'title' => $title, 'authors' => 'Mursyida, A.', 'publication_year' => 2023]);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    expect(garudaVerifications($context)[0]->candidates[0]->source)->toBe(MetadataSource::Crossref);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'garuda.kemdiktisaintek.go.id'));
});

it('fails the run when the first garuda exchange fails', function () {
    CrossrefFake::install();
    GarudaFake::unavailable();

    $tree = DocumentTree::create();
    $tree->reference([
        'doi' => null,
        'title' => 'Literasi Digital Sebagai Upaya Menangkal Hoax di Lingkungan Masyarakat Indonesia',
    ]);

    expect(fn () => app(ValidateReferencesStep::class)->handle($tree->document, new AnalysisContext))
        ->toThrow(GarudaUnavailableException::class);
});

it('degrades only the failing reference once a garuda exchange has succeeded', function () {
    $calls = 0;

    Http::fake([
        'api.crossref.org/*' => Http::response(Fixtures::json('crossref/search-empty')),
        'garuda.kemdiktisaintek.go.id/*' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(Fixtures::text('garuda/search.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])
                : Http::response('', 500);
        },
    ]);

    $tree = DocumentTree::create();
    $tree->reference(['doi' => null, 'title' => 'Literasi Digital Sebagai Upaya Menangkal Hoax di Indonesia']);
    $tree->reference(['doi' => null, 'title' => 'Pembelajaran Berbasis Proyek di Sekolah Dasar Negeri']);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    $verifications = garudaVerifications($context);

    expect($verifications[0]->candidates)->not->toBeEmpty()
        ->and($verifications[1]->transientFailure)->toBeTrue()
        ->and($verifications[1]->pendingReason)->toBe(MatchReasonBuilder::GARUDA_TRANSIENT);
});

it('stops calling garuda and marks the rest pending when the budget is exhausted', function () {
    config(['services.garuda.max_requests_per_document' => 1]);

    CrossrefFake::install();
    GarudaFake::results();

    $tree = DocumentTree::create();
    $tree->reference(['doi' => null, 'title' => 'Literasi Digital Sebagai Upaya Menangkal Hoax di Indonesia']);
    $tree->reference(['doi' => null, 'title' => 'Pembelajaran Berbasis Proyek di Sekolah Dasar Negeri']);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    $verifications = garudaVerifications($context);

    expect($verifications[1]->transientFailure)->toBeTrue()
        ->and($verifications[1]->pendingReason)->toBe(MatchReasonBuilder::GARUDA_BUDGET)
        ->and($verifications[1]->candidates)->toBe([]);
});

it('treats an explicit empty garuda page as a miss for that reference', function () {
    CrossrefFake::install();
    GarudaFake::empty();

    $tree = DocumentTree::create();
    $tree->reference(['doi' => null, 'title' => 'Literasi Digital Sebagai Upaya Menangkal Hoax di Indonesia']);

    $context = new AnalysisContext;
    app(ValidateReferencesStep::class)->handle($tree->document, $context);

    $verification = garudaVerifications($context)[0];

    expect($verification->transientFailure)->toBeFalse()
        ->and($verification->candidates)->toBe([]);
});
