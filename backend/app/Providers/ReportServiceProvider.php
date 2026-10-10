<?php

namespace App\Providers;

use App\Services\Reports\Contracts\ReportRenderer;
use App\Services\Reports\GotenbergReportRenderer;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;

/**
 * Wires report generation: the Gotenberg renderer and its PSR-18 transport.
 *
 * The bound client is only consumed by the renderer in this codebase (D-06-15);
 * tests override it with a Guzzle `MockHandler` client through
 * `$this->instance(ClientInterface::class, ...)`.
 */
final class ReportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A dedicated PSR-18 client so Gotenberg gets explicit timeouts instead of
        // relying on php-http/discovery (D-06-15). Guzzle is already installed as a
        // production dependency via laravel/framework.
        $this->app->singleton(ClientInterface::class, fn (): ClientInterface => new Client([
            'timeout' => (int) config('services.gotenberg.timeout'),
            'connect_timeout' => (int) config('services.gotenberg.connect_timeout'),
        ]));

        // Bound (not singleton) so tests can swap the seam and each job resolves a
        // fresh renderer.
        $this->app->bind(ReportRenderer::class, GotenbergReportRenderer::class);
    }
}
