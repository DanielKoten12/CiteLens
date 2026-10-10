<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Report Generation
    |--------------------------------------------------------------------------
    |
    | On-demand PDF reports (`docs/API_SPEC.md` §8). Reports are rendered by the
    | internal Gotenberg service from a Blade template, stored on a private disk
    | and downloaded through temporary URLs only.
    |
    */

    // Private disk report PDFs are stored on; every `files` row records it (D-06-02).
    // Must name a disk defined in config/filesystems.php.
    'disk' => env('REPORTS_DISK', env('FILESYSTEM_DISK', 'local')),

    // Queue the report job runs on (separate from `analysis` when configured).
    'queue' => env('REPORTS_QUEUE', 'default'),

    // Hard ceiling for one report run (data build + Blade + Gotenberg + store).
    // Must stay above `services.gotenberg.timeout`.
    'timeout' => (int) env('REPORTS_TIMEOUT', 300),

    // Extra seconds the overlap lock outlives the job timeout.
    'lock_expiry_buffer' => (int) env('REPORTS_LOCK_EXPIRY_BUFFER', 60),

    // Blade view rendered to HTML before the renderer.
    'template' => env('REPORTS_TEMPLATE', 'reports.document-report'),

    // Character cap applied to extracted text/titles in the report.
    'text_preview_length' => (int) env('REPORTS_TEXT_PREVIEW_LENGTH', 500),

    // Gotenberg Chromium options (inches, matching the Gotenberg form fields).
    'pdf' => [
        'paper_width' => env('REPORTS_PAPER_WIDTH', '8.27'),
        'paper_height' => env('REPORTS_PAPER_HEIGHT', '11.7'),
        'margin_top' => env('REPORTS_MARGIN_TOP', '0.4'),
        'margin_bottom' => env('REPORTS_MARGIN_BOTTOM', '0.4'),
        'margin_left' => env('REPORTS_MARGIN_LEFT', '0.4'),
        'margin_right' => env('REPORTS_MARGIN_RIGHT', '0.4'),
    ],

];
