<?php

namespace Tests\Support;

use App\Services\Reports\Contracts\ReportRenderer;
use Throwable;

/**
 * In-memory `ReportRenderer` double: records the HTML it was asked to render and
 * returns fake PDF bytes (or throws a configured failure).
 */
final class FakeReportRenderer implements ReportRenderer
{
    public string $lastHtml = '';

    /**
     * @param  callable(): void|null  $onRender  runs before the result is returned
     */
    public function __construct(
        private readonly string $pdf = '%PDF-1.4 fake report',
        private readonly ?Throwable $failure = null,
        private readonly mixed $onRender = null,
    ) {}

    public function render(string $html): string
    {
        $this->lastHtml = $html;

        if ($this->onRender !== null) {
            ($this->onRender)();
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->pdf;
    }
}
