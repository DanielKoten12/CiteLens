<?php

namespace App\Enums;

/**
 * Derived finding severity (`docs/API_SPEC.md` §2.6).
 *
 * `citation_unreliable` severity is inherited from the paired reference finding and
 * is composed by the findings resolver; this enum only carries ordering.
 */
enum FindingSeverity: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    /**
     * Sort rank; higher means more severe.
     */
    public function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
            self::Info => 0,
        };
    }
}
