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
        // Weight of the SBERT semantic term inside the title signal (rest is string similarity).
        'title_blend' => (float) env('SCORING_SEMANTIC_TITLE_BLEND', 0.6),
    ],

    'year_tolerance' => (int) env('SCORING_YEAR_TOLERANCE', 1),

    // Citation resolution (Phase 05.1). Year is a soft signal, not a gate.
    'citation_matching' => [
        // Weighted signals (must sum to 1.0); missing signals renormalize.
        'weights' => [
            'surnames' => (float) env('SCORING_CITATION_WEIGHT_SURNAMES', 0.70),
            'year' => (float) env('SCORING_CITATION_WEIGHT_YEAR', 0.30),
        ],
        // Commit an APA pairing at/above this combined score.
        'commit_threshold' => (float) env('SCORING_CITATION_COMMIT_THRESHOLD', 0.90),
        // Offer a candidate at/above this score; below = no plausible match.
        'proposal_threshold' => (float) env('SCORING_CITATION_PROPOSAL_THRESHOLD', 0.50),
        // How much the winner must beat the runner-up by (Phase 05.1 W2).
        'winner_margin' => (float) env('SCORING_CITATION_WINNER_MARGIN', 0.08),
        // Year distance at which the year signal reaches 0 (1.0 at distance 0).
        'year_window' => (int) env('SCORING_CITATION_YEAR_WINDOW', 5),
        // Subtracted from the surname signal when first initials differ.
        'initial_penalty' => (float) env('SCORING_CITATION_INITIAL_PENALTY', 0.15),
        // Maximum alternatives persisted/exposed per citation (Phase 05.1 W2).
        'max_candidates' => (int) env('SCORING_CITATION_MAX_CANDIDATES', 3),
        // Trust GROBID's reference_index when validated.
        'trust_extraction_hint' => (bool) env('SCORING_CITATION_TRUST_EXTRACTION_HINT', true),
    ],

    // Conservative defaults for likely non-indexed/local venues; tuned in Phase 07.
    'local_venue_keywords' => [
        // 'jurnal lokal',
        // 'prosiding nasional',
    ],

];
