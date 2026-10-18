<?php

namespace App\Services\Reports;

use App\Exceptions\ReportRenderingException;
use App\Services\Reports\Contracts\ReportRenderer;
use Gotenberg\Exceptions\GotenbergApiErrored;
use Gotenberg\Gotenberg;
use Gotenberg\Stream;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Renders report HTML to PDF through the internal Gotenberg service.
 *
 * Uses the official `gotenberg/gotenberg-php` client for the multipart request
 * (Gotenberg 8.x, D-06-14) and an injected PSR-18 client for transport, so
 * timeouts come from `services.gotenberg` instead of `php-http/discovery`
 * (D-06-15). Gotenberg is never exposed publicly.
 */
final class GotenbergReportRenderer implements ReportRenderer
{
    public function __construct(
        private readonly ClientInterface $http,
    ) {}

    public function render(string $html): string
    {
        $request = Gotenberg::chromium((string) config('services.gotenberg.base_url'))
            ->pdf()
            ->paperSize(
                (string) config('reports.pdf.paper_width'),
                (string) config('reports.pdf.paper_height'),
            )
            ->margins(
                (string) config('reports.pdf.margin_top'),
                (string) config('reports.pdf.margin_bottom'),
                (string) config('reports.pdf.margin_left'),
                (string) config('reports.pdf.margin_right'),
            )
            ->html(Stream::string('index.html', $html));

        try {
            $response = Gotenberg::send($request, $this->http);
        } catch (GotenbergApiErrored $exception) {
            throw ReportRenderingException::unexpectedResponse((int) $exception->getCode(), $exception);
        } catch (NetworkExceptionInterface|ClientExceptionInterface $exception) {
            throw ReportRenderingException::serviceUnavailable($exception);
        }

        $body = (string) $response->getBody();

        if ($body === '' || ! str_starts_with(ltrim($body), '%PDF')) {
            throw ReportRenderingException::malformedResponse();
        }

        return $body;
    }
}
