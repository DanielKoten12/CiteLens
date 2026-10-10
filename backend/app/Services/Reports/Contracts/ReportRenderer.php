<?php

namespace App\Services\Reports\Contracts;

use App\Exceptions\ReportRenderingException;

/**
 * HTML → PDF rendering seam for generated document reports.
 *
 * Implemented by the Gotenberg client in production and replaced by a fake in
 * tests, so the generation job never depends on a live rendering service.
 */
interface ReportRenderer
{
    /**
     * Render a self-contained HTML document to PDF bytes.
     *
     * @throws ReportRenderingException when the renderer is unavailable or returns an invalid PDF
     */
    public function render(string $html): string;
}
