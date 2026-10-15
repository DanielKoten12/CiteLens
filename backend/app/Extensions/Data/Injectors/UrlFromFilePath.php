<?php

namespace App\Extensions\Data\Injectors;

use App\Services\Files\PrivateFileUrlResolver;
use Attribute;
use Spatie\LaravelData\Attributes\InjectsPropertyValue;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;

/**
 * Resolve a stored-file URL from the payload's `path` (and optional `disk`).
 *
 * The URL rule itself lives in {@see PrivateFileUrlResolver} (D-06-03); this
 * injector only adapts a `File` row / array payload to it.
 */
#[Attribute]
readonly class UrlFromFilePath implements InjectsPropertyValue
{
    public function __construct(
        private string $field = 'path',
    ) {}

    public function resolve(
        DataProperty $dataProperty,
        mixed $payload,
        array $properties,
        CreationContext $creationContext
    ): mixed {
        return app(PrivateFileUrlResolver::class)->resolve(
            $payload[$this->field] ?? null,
            $payload['disk'] ?? null,
        );
    }

    public function shouldBeReplacedWhenPresentInPayload(): bool
    {
        return true;
    }
}
