<?php

namespace App\Extensions\Data\Injectors;

use Attribute;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelData\Attributes\InjectsPropertyValue;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;
use Throwable;

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
        $disk = Storage::disk((string) config('filesystems.default'));

        $path = $payload[$this->field];

        try {
            return $disk->providesTemporaryUrls()
                ? $disk->temporaryUrl($path, now()->addHour())
                : $disk->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    public function shouldBeReplacedWhenPresentInPayload(): bool
    {
        return true;
    }


}
