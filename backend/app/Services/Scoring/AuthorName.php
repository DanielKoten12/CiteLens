<?php

namespace App\Services\Scoring;

/**
 * A parsed author name: surname plus optional initials.
 *
 * Used by the citation resolver to disambiguate same-surname authors
 * (`Koten, D.` vs `Koten, A.`) without changing the surname-only similarity used
 * by reference scoring.
 */
final class AuthorName
{
    /**
     * @param  string  $surname  trimmed comparison form
     * @param  string|null  $initials  uppercase letters, e.g. `DB` for `D. B.`
     */
    public function __construct(
        public readonly string $surname,
        public readonly ?string $initials = null,
    ) {}

    /**
     * The first initial, or null when unknown.
     */
    public function firstInitial(): ?string
    {
        if ($this->initials === null || $this->initials === '') {
            return null;
        }

        return mb_substr($this->initials, 0, 1);
    }
}
