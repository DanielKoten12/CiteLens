<?php

namespace App\Services\Reports;

use App\Services\Reports\Contracts\ReportRenderer;
use Illuminate\Support\Facades\View;

/**
 * Blade → HTML renderer for the report template.
 *
 * Kept separate from {@see ReportRenderer} (HTML
 * → PDF bytes) so both halves are independently testable and a future renderer
 * does not touch views (D-06-09). The rendered HTML is self-contained: inline
 * styles only, no external assets, no JavaScript.
 */
final class ReportTemplateRenderer
{
    public function render(ReportPayload $payload): string
    {
        return View::make((string) config('reports.template'), ['payload' => $payload])->render();
    }
}
