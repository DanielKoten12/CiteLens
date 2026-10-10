<?php

namespace App\Services\Citations;

/**
 * Structured result of parsing one in-text citation marker
 * (`docs/API_SPEC.md` §6; proposal scope: APA + IEEE only).
 *
 * Unlike Phase 05, a marker is no longer reduced to its first pair/ordinal:
 * every APA pair and every IEEE ordinal is retained so the resolver can score
 * each of them and persist the alternatives as candidates (D-05.1-07).
 */
final class ParsedCitationMarker
{
    /**
     * @param  list<ParsedAuthorYear>  $pairs  APA author/year pairs, marker order
     * @param  list<int>  $ordinals  IEEE ordinals, ascending and unique
     */
    public function __construct(
        public readonly array $pairs = [],
        public readonly array $ordinals = [],
    ) {}

    public function isIeee(): bool
    {
        return $this->ordinals !== [];
    }

    public function isApa(): bool
    {
        return ! $this->isIeee() && $this->pairs !== [];
    }

    /**
     * Whether the marker carries enough structure to attempt a pairing.
     */
    public function isParsed(): bool
    {
        return $this->isIeee() || $this->pairs !== [];
    }

    /**
     * The pair used for the primary APA decision.
     */
    public function primaryPair(): ?ParsedAuthorYear
    {
        return $this->pairs[0] ?? null;
    }
}
