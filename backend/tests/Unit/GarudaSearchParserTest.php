<?php

use App\Services\Garuda\GarudaSearchParser;
use Tests\Support\Fixtures;

it('reads the result marker and every card from a search page', function () {
    $html = Fixtures::text('garuda/search.html');
    $parser = new GarudaSearchParser;

    expect($parser->total($html))->toBe(425);

    $cards = $parser->parse($html);

    expect($cards)->toHaveCount(10)
        ->and($cards[0]['id'])->toBe('6584846')
        ->and($cards[0]['year'])->toBe(2026)
        ->and($cards[0]['authors'])->toHaveCount(7)
        ->and($cards[0]['journal'])->toContain('JESASI')
        ->and($cards[0]['publisher'])->toBe('PT. CASA CENDEKIA MEDIA')
        ->and($cards[1]['year'])->toBe(2023);
});

it('treats an explicit zero-result page as an empty result', function () {
    $html = Fixtures::text('garuda/search-empty.html');
    $parser = new GarudaSearchParser;

    expect($parser->total($html))->toBe(0)
        ->and($parser->parse($html))->toBe([]);
});

it('returns no cards and no marker for an unknown page', function () {
    $parser = new GarudaSearchParser;

    expect($parser->total(Fixtures::text('garuda/search-malformed.html')))->toBeNull()
        ->and($parser->parse(Fixtures::text('garuda/search-malformed.html')))->toBe([]);
});

it('reports the marker even when the cards no longer parse', function () {
    $html = Fixtures::text('garuda/search-changed.html');
    $parser = new GarudaSearchParser;

    expect($parser->total($html))->toBe(5)
        ->and($parser->parse($html))->toBe([]);
});

it('preserves non-ascii characters in titles and authors', function () {
    $html = <<<'HTML'
        <html><body><p>Found 1 documents</p>
        <div class="article-item">
          <a class="title-article" href="/documents/detail/42"><xmp>Analisis José Müller</xmp></a>
          <a class="author-article"><xmp>José Müller</xmp></a>
          <xmp class="subtitle-article">Jurnal Pendidikan Vol. 1 No. 2 (2021): X</xmp>
        </div></body></html>
        HTML;

    $cards = (new GarudaSearchParser)->parse($html);

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['title'])->toBe('Analisis José Müller')
        ->and($cards[0]['authors'])->toBe(['José Müller']);
});
