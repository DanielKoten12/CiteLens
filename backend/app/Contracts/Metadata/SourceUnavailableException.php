<?php

namespace App\Contracts\Metadata;

use App\Enums\MetadataSource;
use Throwable;

/**
 * Marker contract for the exception a metadata source throws when it cannot
 * answer (transport error, timeout, unexpected status, unusable payload).
 *
 * The verification chains apply a per-source failure policy without matching
 * on each concrete exception class: the source is the only thing they need to
 * decide between `fatal` and `degrade` and to build the pending reason.
 */
interface SourceUnavailableException extends Throwable
{
    public function source(): MetadataSource;

    /**
     * The safe, human-readable category of the failure (e.g. `rate limited`).
     *
     * Never includes response bodies, query text, DOIs or credentials; it is
     * used for logging and diagnostics only.
     */
    public function reason(): string;
}
