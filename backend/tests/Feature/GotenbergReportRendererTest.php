<?php

use App\Exceptions\ReportRenderingException;
use App\Services\Reports\Contracts\ReportRenderer;
use App\Services\Reports\GotenbergReportRenderer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function rendererWith(MockHandler $handler): GotenbergReportRenderer
{
    return new GotenbergReportRenderer(new Client(['handler' => HandlerStack::create($handler)]));
}

it('returns the pdf bytes and targets the chromium html endpoint', function () {
    $handler = new MockHandler([new Response(200, [], '%PDF-1.4 ok')]);

    $pdf = rendererWith($handler)->render('<html>hello</html>');

    expect($pdf)->toBe('%PDF-1.4 ok');

    $request = $handler->getLastRequest();

    expect($request)->not->toBeNull()
        ->and((string) $request?->getUri())
        ->toBe(rtrim((string) config('services.gotenberg.base_url'), '/').'/forms/chromium/convert/html')
        ->and((string) $request?->getBody())->toContain('index.html', '<html>hello</html>');
});

it('sends the configured paper size and margins', function () {
    config([
        'reports.pdf.paper_width' => '10',
        'reports.pdf.paper_height' => '14',
        'reports.pdf.margin_top' => '1',
        'reports.pdf.margin_bottom' => '2',
        'reports.pdf.margin_left' => '3',
        'reports.pdf.margin_right' => '4',
    ]);

    $handler = new MockHandler([new Response(200, [], '%PDF-1.4 ok')]);

    rendererWith($handler)->render('<html>sizes</html>');

    $body = (string) $handler->getLastRequest()?->getBody();

    expect($body)->toContain('name="paperWidth"')
        ->and($body)->toContain('name="paperHeight"')
        ->and($body)->toContain('name="marginTop"')
        ->and($body)->toContain('name="marginBottom"')
        ->and($body)->toContain('name="marginLeft"')
        ->and($body)->toContain('name="marginRight"')
        ->and($body)->toContain('10')
        ->and($body)->toContain('14')
        ->and($body)->toContain('1')
        ->and($body)->toContain('2')
        ->and($body)->toContain('3')
        ->and($body)->toContain('4');
});

it('maps a non-2xx response to unexpectedResponse', function () {
    $handler = new MockHandler([new Response(500, [], 'boom')]);

    try {
        rendererWith($handler)->render('<html>crash</html>');
        $this->fail('Expected a ReportRenderingException.');
    } catch (ReportRenderingException $exception) {
        expect($exception->getPrevious())->not->toBeNull()
            ->and($exception->getMessage())->toContain('500');
    }
});

it('maps a connection failure to serviceUnavailable', function () {
    $handler = new MockHandler([
        new ConnectException('Connection refused', new Request('POST', 'http://gotenberg:3000')),
    ]);

    try {
        rendererWith($handler)->render('<html>offline</html>');
        $this->fail('Expected a ReportRenderingException.');
    } catch (ReportRenderingException $exception) {
        expect($exception->getMessage())->toContain('could not be reached')
            ->and($exception->getPrevious())->not->toBeNull();
    }
});

it('rejects an empty or non-pdf body', function (string $body) {
    $handler = new MockHandler([new Response(200, [], $body)]);

    expect(fn () => rendererWith($handler)->render('<html>bad</html>'))
        ->toThrow(ReportRenderingException::class);
})->with([
    'empty' => '',
    'html' => '<html>not a pdf</html>',
]);

it('binds the gotenberg renderer by default', function () {
    expect(app(ReportRenderer::class))->toBeInstanceOf(GotenbergReportRenderer::class);
});
