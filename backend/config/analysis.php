<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document Analysis
    |--------------------------------------------------------------------------
    |
    | Queue orchestration for the asynchronous document-analysis pipeline
    | (`docs/API_SPEC.md` §10). This file owns the queue the job runs on, its
    | timeout, the overlap-lock buffer, the progress map and the extraction
    | persistence bounds.
    |
    */

    // Queue the analysis job runs on.
    'queue' => env('ANALYSIS_QUEUE', 'default'),

    // Hard ceiling for a single analysis run, in seconds.
    'timeout' => (int) env('ANALYSIS_TIMEOUT', 900),

    // Extra seconds the overlap lock outlives the job timeout, so a worker that is
    // killed mid-run can never wedge a document behind an unexpired lock.
    'lock_expiry_buffer' => (int) env('ANALYSIS_LOCK_EXPIRY_BUFFER', 60),

    /*
    | Progress floors/ceilings per canonical step (`docs/API_SPEC.md` §2.6,
    | `docs/plans/backend/03-analysis-pipeline.md` §3.6). `AnalysisProgress`
    | interpolates inside `[floor, ceiling]` and only `complete()` reaches 100.
    */
    'progress' => [
        'queued' => ['floor' => 0, 'ceiling' => 0],
        'extracting' => ['floor' => 5, 'ceiling' => 25],
        'persisting' => ['floor' => 25, 'ceiling' => 35],
        'crossref_validation' => ['floor' => 35, 'ceiling' => 60],
        'embedding' => ['floor' => 60, 'ceiling' => 70],
        'scoring' => ['floor' => 70, 'ceiling' => 85],
        'resolving_citations' => ['floor' => 85, 'ceiling' => 95],
        'generating_report' => ['floor' => 95, 'ceiling' => 99],
        'completed' => ['floor' => 100, 'ceiling' => 100],
    ],

    /*
    | Bounds applied when persisting an extraction payload. Text is capped for
    | MySQL `text` portability; an over-long value is a fatal extraction error,
    | never a silent truncation. Years outside the range become null.
    */
    'extraction' => [
        'max_text_length' => (int) env('ANALYSIS_MAX_TEXT_LENGTH', 65535),
        'max_year' => 2100,
    ],

];
