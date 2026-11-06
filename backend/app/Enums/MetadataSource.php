<?php

namespace App\Enums;

/**
 * External catalogue a candidate publication record came from
 * (`docs/API_SPEC.md` §5, `reference_finding_candidates.source`).
 *
 * `Crossref` and `OpenAlex` are primary sources; `Garuda` is the Indonesian
 * fallback. The value is the stored/API representation, so it must stay
 * `lower_snake_case` and stable.
 */
enum MetadataSource: string
{
    case Crossref = 'crossref';
    case OpenAlex = 'openalex';
    case Garuda = 'garuda';

    /**
     * Human-readable catalogue name, used in candidate `match_reason` text.
     */
    public function label(): string
    {
        return match ($this) {
            self::Crossref => 'Crossref',
            self::OpenAlex => 'OpenAlex',
            self::Garuda => 'Garuda',
        };
    }
}
