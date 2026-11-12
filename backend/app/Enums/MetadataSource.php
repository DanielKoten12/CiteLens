<?php

namespace App\Enums;

/**
 * External catalogue a candidate publication record came from
 * (`docs/API_SPEC.md` §5, `reference_finding_candidates.source`).
 *
 * Crossref is the primary, authoritative source; Garuda, Semantic Scholar and
 * OpenAlex are supplemental sources consulted as an ordered fallback chain.
 * The value is the stored/API representation, so it must stay
 * `lower_snake_case` and stable.
 */
enum MetadataSource: string
{
    case Crossref = 'crossref';
    case OpenAlex = 'openalex';
    case Garuda = 'garuda';
    case SemanticScholar = 'semantic_scholar';

    /**
     * Human-readable catalogue name, used in candidate `match_reason` text and
     * pending-failure reasons.
     */
    public function label(): string
    {
        return match ($this) {
            self::Crossref => 'Crossref',
            self::OpenAlex => 'OpenAlex',
            self::Garuda => 'Garuda',
            self::SemanticScholar => 'Semantic Scholar',
        };
    }
}
