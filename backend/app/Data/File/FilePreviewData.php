<?php

namespace App\Data\File;

use App\Data\BaseData;
use App\Extensions\Data\Injectors\UrlFromFilePath;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class FilePreviewData extends BaseData
{
    public string $filename;
    public ?string $mimeType;
    public ?int $size;
    #[UrlFromFilePath(field: 'path')]
    public ?string $url;
}
