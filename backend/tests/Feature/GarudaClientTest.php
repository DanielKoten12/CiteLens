<?php

use App\Enums\MetadataSource;
use App\Exceptions\GarudaUnavailableException;
use App\Services\Garuda\GarudaClient;
use App\Services\Metadata\ReferenceQuery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.garuda.enabled' => true,
        'services.garuda.base_url' => 'https://garuda.kemdiktisaintek.go.id',
        'services.garuda.timeout' => 5,
        'services.garuda.connect_timeout' => 2,
        'services.garuda.retries' => 0,
        'services.garuda.retry_backoff_ms' => 0,
        'services.garuda.rows' => 3,
        'services.garuda.query_max_length' => 180,
        'services.garuda.cache_ttl' => 3600,
        'services.garuda.negative_cache_ttl' => 60,
        'services.garuda.min_interval_ms' => 0,
        'services.garuda.max_requests_per_document' => 500,
        'services.garuda.max_response_bytes' => 2097152,
        'services.garuda.user_agent' => null,
        'services.crossref.mailto' => 'test@example.com',
    ]);
});

function fakeGaruda(string $fixture, int $status = 200, string $contentType = 'text/html; charset=UTF-8'): void
{
    Http::fake([
        'garuda.kemdiktisaintek.go.id/*' => Http::response(
            Fixtures::text('garuda/'.$fixture),
            $status,
            ['Content-Type' => $contentType],
        ),
    ]);
}

it('parses and maps the top search results', function () {
    fakeGaruda('search.html');

    $works = app(GarudaClient::class)->search(
        new ReferenceQuery('x', title: 'Literasi Digital Sebagai Upaya Menangkal Hoax'),
    );

    expect($works)->toHaveCount(3)
        ->and($works[0]->source)->toBe(MetadataSource::Garuda)
        ->and($works[0]->doi)->toBeNull()
        ->and($works[0]->containerTitle)->toBe('JESASI (JURNAL EDUKASI, SAINS, DAN INOVASI)')
        ->and($works[0]->url)->toBe('https://garuda.kemdiktisaintek.go.id/documents/detail/6584846');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/documents?')
        && str_contains($request->url(), 'q=Literasi%20Digital')
        && str_contains((string) $request->header('User-Agent')[0], 'mailto:test@example.com'));
});

it('caches a search and does not hit http twice', function () {
    fakeGaruda('search.html');

    $client = app(GarudaClient::class);
    $query = new ReferenceQuery('x', title: 'Literasi Digital Sebagai Upaya Menangkal Hoax');

    $client->search($query);
    $client->search($query);

    Http::assertSentCount(1);
});

it('does not call garuda when the source is disabled', function () {
    config(['services.garuda.enabled' => false]);
    fakeGaruda('search.html');

    expect(app(GarudaClient::class)->search(new ReferenceQuery('x', title: 'Literasi Digital')))->toBe([]);

    Http::assertNothingSent();
});

it('returns an empty list for an explicit zero-result page', function () {
    fakeGaruda('search-empty.html');

    expect(app(GarudaClient::class)->search(new ReferenceQuery('x', title: 'Tidak ada hasil sama sekali')))
        ->toBe([]);
});

it('throws when the page has no result marker', function () {
    fakeGaruda('search-malformed.html');

    expect(fn () => app(GarudaClient::class)->search(new ReferenceQuery('x', title: 'Literasi Digital')))
        ->toThrow(GarudaUnavailableException::class);
});

it('throws when the result marker disagrees with the parsed cards', function () {
    fakeGaruda('search-changed.html');

    expect(fn () => app(GarudaClient::class)->search(new ReferenceQuery('x', title: 'Literasi Digital')))
        ->toThrow(GarudaUnavailableException::class);
});

it('retries a server error before giving up', function () {
    config(['services.garuda.retries' => 1]);
    fakeGaruda('search-empty.html', 500);

    expect(fn () => app(GarudaClient::class)->search(new ReferenceQuery('x', title: 'Literasi Digital')))
        ->toThrow(GarudaUnavailableException::class);

    Http::assertSentCount(2);
});

it('throws on a connection error', function () {
    Http::fake(['garuda.kemdiktisaintek.go.id/*' => fn () => throw new ConnectionException('Connection refused.')]);

    expect(fn () => app(GarudaClient::class)->search(new ReferenceQuery('x', title: 'Literasi Digital')))
        ->toThrow(GarudaUnavailableException::class);
});

it('throws when the response is not html', function () {
    fakeGaruda('search.html', contentType: 'application/json');

    expect(fn () => app(GarudaClient::class)->search(new ReferenceQuery('x', title: 'Literasi Digital')))
        ->toThrow(GarudaUnavailableException::class);
});

it('throws when the response exceeds the configured size cap', function () {
    config(['services.garuda.max_response_bytes' => 100]);
    fakeGaruda('search.html');

    expect(fn () => app(GarudaClient::class)->search(new ReferenceQuery('x', title: 'Literasi Digital')))
        ->toThrow(GarudaUnavailableException::class);
});

it('skips the request for a title too short to search', function () {
    fakeGaruda('search.html');

    expect(app(GarudaClient::class)->search(new ReferenceQuery('', title: 'abc')))->toBe([]);

    Http::assertNothingSent();
});

it('throws on an unexpected 4xx', function () {
    fakeGaruda('search-empty.html', 404);

    expect(fn () => app(GarudaClient::class)->search(new ReferenceQuery('x', title: 'Literasi Digital')))
        ->toThrow(GarudaUnavailableException::class);
});
