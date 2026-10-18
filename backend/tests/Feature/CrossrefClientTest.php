<?php

use App\Exceptions\CrossrefUnavailableException;
use App\Services\Crossref\CrossrefClient;
use App\Services\Crossref\ReferenceQuery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.crossref.base_url' => 'https://api.crossref.org',
        'services.crossref.mailto' => 'test@example.com',
        'services.crossref.rows' => 5,
        'services.crossref.cache_ttl' => 86400,
        'services.crossref.retries' => 0,
        'services.crossref.retry_backoff_ms' => 0,
        'services.crossref.user_agent' => null,
    ]);
});

it('resolves a doi and maps the work', function () {
    Http::fake(['api.crossref.org/*' => Http::response(Fixtures::json('crossref/doi-found'))]);

    $work = app(CrossrefClient::class)->findByDoi('10.1038/nature14539');

    expect($work)->not->toBeNull()
        ->doi->toBe('10.1038/nature14539')
        ->title->toBe('Deep learning')
        ->containerTitle->toBe('Nature')
        ->publicationYear->toBe(2015)
        ->authors->toBe(['Yann LeCun', 'Yoshua Bengio', 'Geoffrey Hinton']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/works/10.1038/nature14539'));
});

it('returns null when the doi does not resolve', function () {
    Http::fake(['api.crossref.org/*' => Http::response('', 404)]);

    expect(app(CrossrefClient::class)->findByDoi('10.1234/missing'))->toBeNull();
});

it('caches a resolved doi and does not hit http twice', function () {
    Http::fake(['api.crossref.org/*' => Http::response(Fixtures::json('crossref/doi-found'))]);

    $client = app(CrossrefClient::class);
    $client->findByDoi('10.1038/nature14539');
    $client->findByDoi('10.1038/nature14539');

    Http::assertSentCount(1);
});

it('caches a doi miss too', function () {
    Http::fake(['api.crossref.org/*' => Http::response('', 404)]);

    $client = app(CrossrefClient::class);
    $client->findByDoi('10.1234/missing');
    $client->findByDoi('10.1234/missing');

    Http::assertSentCount(1);
});

it('retries a transient server error and succeeds', function () {
    config(['services.crossref.retries' => 1]);

    $attempts = 0;

    Http::fake(['api.crossref.org/*' => function () use (&$attempts) {
        $attempts++;

        return $attempts === 1
            ? Http::response('', 503)
            : Http::response(Fixtures::json('crossref/doi-found'));
    }]);

    $work = app(CrossrefClient::class)->findByDoi('10.1038/nature14539');

    expect($attempts)->toBe(2)
        ->and($work)->not->toBeNull();
});

it('throws when every attempt fails with a server error', function () {
    config(['services.crossref.retries' => 1]);

    Http::fake(['api.crossref.org/*' => Http::response('', 503)]);

    expect(fn () => app(CrossrefClient::class)->findByDoi('10.1038/nature14539'))
        ->toThrow(CrossrefUnavailableException::class);
});

it('throws on a connection error', function () {
    Http::fake(['api.crossref.org/*' => fn () => throw new ConnectionException('Connection refused.')]);

    expect(fn () => app(CrossrefClient::class)->findByDoi('10.1038/nature14539'))
        ->toThrow(CrossrefUnavailableException::class);
});

it('maps a bibliographic search and sends the documented params', function () {
    Http::fake(['api.crossref.org/*' => Http::response(Fixtures::json('crossref/search-multiple'))]);

    $results = app(CrossrefClient::class)->searchBibliographic(
        new ReferenceQuery('Sistem deteksi plagiarisme Koten 2023', 'Koten', 2023),
    );

    expect($results)->toHaveCount(2)
        ->and($results[0]->doi)->toBe('10.1234/example')
        ->and($results[1]->doi)->toBe('10.1234/distractor');

    Http::assertSent(function (Request $request): bool {
        $url = $request->url();

        return str_starts_with($url, 'https://api.crossref.org/works?')
            && str_contains($url, 'query.bibliographic=Sistem%20deteksi%20plagiarisme%20Koten%202023')
            && str_contains($url, 'query.author=Koten')
            && str_contains($url, 'rows=5')
            && str_contains($url, 'mailto=test%40example.com')
            && str_contains((string) $request->header('User-Agent')[0], 'mailto:test@example.com');
    });
});

it('does not cache bibliographic searches', function () {
    Http::fake(['api.crossref.org/*' => Http::response(Fixtures::json('crossref/search-multiple'))]);

    $client = app(CrossrefClient::class);
    $query = new ReferenceQuery('Deep learning');

    $client->searchBibliographic($query);
    $client->searchBibliographic($query);

    Http::assertSentCount(2);
});

it('throws on an unexpected 4xx from a search', function () {
    Http::fake(['api.crossref.org/*' => Http::response('', 400)]);

    expect(fn () => app(CrossrefClient::class)->searchBibliographic(new ReferenceQuery('Deep learning')))
        ->toThrow(CrossrefUnavailableException::class);
});

it('returns an empty list for a search with no items', function () {
    Http::fake(['api.crossref.org/*' => Http::response(Fixtures::json('crossref/search-empty'))]);

    expect(app(CrossrefClient::class)->searchBibliographic(new ReferenceQuery('Nothing')))->toBe([]);
});
