<?php

namespace App\Data\Error;

use App\Data\BaseData;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * The `error` object of the canonical API error envelope
 * (`docs/API_SPEC.md` §2.5):
 *
 * ```json
 * { "error": { "code": "VALIDATION_ERROR", "message": "…", "details": { … } } }
 * ```
 *
 * `details` is optional and omitted from the serialized output when there is
 * nothing to add (e.g. `401`/`429` responses).
 */
#[MapName(SnakeCaseMapper::class)]
class ErrorResponseData extends BaseData
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $code,
        public string $message,
        public Optional|array $details = new Optional,
    ) {}
}
