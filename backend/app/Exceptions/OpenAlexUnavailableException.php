<?php

namespace App\Exceptions;

use App\Contracts\Metadata\SourceUnavailableException;
use App\Enums\MetadataSource;
use RuntimeException;
use Throwable;

/**
 * Raised when the OpenAlex Works API cannot answer: transport error, timeout,
 * `429` (per-second limit or exhausted daily budget), `5xx`, a credential
 * problem or a payload the mapper no longer understands.
 *
 * Default policy is `degrade` (D-09-04): the chain records the failure and keeps
 * going, so an outage never becomes a false `not_found`. The raw exception goes
 * to the log; the safe user-facing literal is owned by the analysis failure
 * handler, and only reachable when an operator configures the source as fatal.
 */
final class OpenAlexUnavailableException extends RuntimeException implements SourceUnavailableException
{
    private string $reason = 'service unavailable';

    public static function serviceUnavailable(?Throwable $previous = null): self
    {
        return new self('OpenAlex could not be reached.', previous: $previous);
    }

    public static function unexpectedResponse(int $status, ?Throwable $previous = null): self
    {
        $exception = new self("OpenAlex responded with an unexpected HTTP status {$status}.", previous: $previous);
        $exception->reason = "unexpected HTTP status {$status}";

        return $exception;
    }

    public static function unexpectedFormat(string $reason): self
    {
        $exception = new self("OpenAlex returned an unexpected response: {$reason}");
        $exception->reason = 'unexpected response format';

        return $exception;
    }

    public static function rateLimited(?int $retryAfterSeconds = null): self
    {
        $exception = new self('OpenAlex rate limit or daily budget exhausted.');
        $exception->reason = 'rate limited';

        return $exception;
    }

    public static function unauthorized(): self
    {
        $exception = new self('OpenAlex rejected the configured credentials.');
        $exception->reason = 'unauthorized';

        return $exception;
    }

    public function source(): MetadataSource
    {
        return MetadataSource::OpenAlex;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
