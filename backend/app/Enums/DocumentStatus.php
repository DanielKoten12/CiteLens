<?php

namespace App\Enums;

/**
 * Lifecycle of an uploaded research document (`researched_documents.status`).
 *
 * Canonical values: `docs/API_SPEC.md` §2.6.
 */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Whether the document can no longer change state on its own.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Pending, self::Processing => false,
        };
    }

    /**
     * Retry is only allowed for failed documents (`docs/API_SPEC.md` §4).
     */
    public function allowsRetry(): bool
    {
        return $this === self::Failed;
    }
}
