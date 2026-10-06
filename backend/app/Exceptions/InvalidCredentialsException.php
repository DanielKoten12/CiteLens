<?php

namespace App\Exceptions;

/**
 * Raised when login credentials do not match a user.
 *
 * An unknown email and a wrong password share this exception and its message so
 * the response cannot be used to enumerate accounts (`docs/SECURITY.md` §2).
 */
class InvalidCredentialsException extends ApiException
{
    public static function create(): self
    {
        return new self('Kredensial tidak valid.');
    }

    public function code(): string
    {
        return 'VALIDATION_ERROR';
    }

    public function status(): int
    {
        return 422;
    }

    /**
     * Mirror the framework's validation detail shape so clients can render it
     * like any other field error.
     *
     * @return array<string, mixed>
     */
    public function details(): ?array
    {
        return ['email' => ['These credentials do not match our records.']];
    }
}
