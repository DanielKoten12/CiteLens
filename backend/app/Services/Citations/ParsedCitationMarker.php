<?php

namespace App\Services\Citations;

/**
 * Structured result of parsing one in-text citation marker
 * (`docs/API_SPEC.md` §6; proposal scope: APA + IEEE only).
 *
 * IEEE markers carry numeric ordinals (`[3]`, `[3-5]`); APA markers carry
 * surnames and a year (`(Koten, 2023)`, `Koten et al. (2023)`). The shapes are
 * mutually exclusive by construction: a marker is IEEE when any bracketed
 * ordinal is found, otherwise it is parsed for an APA author/year pair.
 */
final class ParsedCitationMarker
{
    /**
     * @param  list<string>  $surnames  APA surnames, in marker order
     * @param  list<int>  $ordinals  IEEE reference ordinals, ascending and unique
     */
    public function __construct(
        public readonly array $surnames = [],
        public readonly ?int $year = null,
        public readonly array $ordinals = [],
    ) {}

    public function isIeee(): bool
    {
        return $this->ordinals !== [];
    }

    public function isApa(): bool
    {
        return ! $this->isIeee() && ($this->surnames !== [] || $this->year !== null);
    }

    /**
     * Whether the marker carries enough structure to attempt a pairing.
     */
    public function isParsed(): bool
    {
        return $this->isIeee() || $this->surnames !== [];
    }
}
