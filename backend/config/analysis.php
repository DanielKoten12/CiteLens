<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document Analysis
    |--------------------------------------------------------------------------
    |
    | Queue orchestration for the asynchronous document-analysis pipeline
    | (`docs/API_SPEC.md` §10). The pipeline itself is implemented in Phase 03;
    | this file owns the queue the job runs on and its timeout.
    |
    */

    // Queue the analysis job runs on.
    'queue' => env('ANALYSIS_QUEUE', 'default'),

    // Hard ceiling for a single analysis run, in seconds.
    'timeout' => (int) env('ANALYSIS_TIMEOUT', 900),

];
