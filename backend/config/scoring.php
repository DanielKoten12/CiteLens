<?php

/*
|--------------------------------------------------------------------------
| Scoring Configuration
|--------------------------------------------------------------------------
|
| Provisional matching configuration for the reference verification pipeline
| (OQ-12). The proposal's valid threshold is 0.85; the remaining defaults are
| provisional and are tuned through the Phase 07 evaluation harness. Every
| evaluation run must record the configuration used.
|
| The signal weights must sum to 1.0; semantic similarity is one signal among
| others and is never sufficient alone (`docs/ARCHITECTURE.md` §7.1).
|
*/

return [

    'thresholds' => [
        'valid' => (float) env('SCORING_VALID_THRESHOLD', 0.85),
        'suspicious' => (float) env('SCORING_SUSPICIOUS_THRESHOLD', 0.50),
    ],

    // title + authors + journal + year = 1.0
    'weights' => [
        'title' => (float) env('SCORING_WEIGHT_TITLE', 0.45),
        'authors' => (float) env('SCORING_WEIGHT_AUTHORS', 0.25),
        'journal' => (float) env('SCORING_WEIGHT_JOURNAL', 0.15),
        'year' => (float) env('SCORING_WEIGHT_YEAR', 0.15),
    ],

    'semantic' => [
        'enabled' => (bool) env('SCORING_SEMANTIC_ENABLED', true),
    ],

    'year_tolerance' => (int) env('SCORING_YEAR_TOLERANCE', 1),

    // Conservative defaults for likely non-indexed/local venues; tuned in Phase 07.
    'local_venue_keywords' => [
        // 'jurnal lokal',
        // 'prosiding nasional',
    ],

];
