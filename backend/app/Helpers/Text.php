<?php

namespace App\Helpers;

use Illuminate\Support\Arr;

readonly class Text
{
    /**
     * @param  string[]  $items
     * @return string[]
     */
    public static function arrayPrefix(string $prefix, array $items): array
    {
        return Arr::map($items, fn (string $item) => "{$prefix}{$item}");
    }
}
