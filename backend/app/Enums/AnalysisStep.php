<?php

namespace App\Enums;

/**
 * Ordered pipeline step of the document analysis (`researched_documents.analysis_step`).
 *
 * The declaration order is canonical: `docs/API_SPEC.md` §2.6.
 */
enum AnalysisStep: string
{
    case Queued = 'queued';
    case Extracting = 'extracting';
    case Persisting = 'persisting';
    case CrossrefValidation = 'crossref_validation';
    case Embedding = 'embedding';
    case Scoring = 'scoring';
    case ResolvingCitations = 'resolving_citations';
    case GeneratingReport = 'generating_report';
    case Completed = 'completed';

    /**
     * The canonical step sequence. Kept explicit so reordering cases cannot
     * silently change the pipeline.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Queued,
            self::Extracting,
            self::Persisting,
            self::CrossrefValidation,
            self::Embedding,
            self::Scoring,
            self::ResolvingCitations,
            self::GeneratingReport,
            self::Completed,
        ];
    }

    /**
     * Whether this step marks the end of the pipeline.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed;
    }
}
