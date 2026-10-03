<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class BaseData extends Data
{
    /**
     * This act as global wrapping & disables nested collection wrapping.
     *
     * Nested collection wrapping just doesn't seem to make sense given we are not following JSON:API spec in the first place.
     *
     * @see https://github.com/spatie/laravel-data/discussions/737#discussioncomment-12327849
     * @see https://spatie.be/docs/laravel-data/v4/as-a-resource/wrapping#content-nested-wrapping
     */
    public function defaultWrap(): string
    {
        return 'data';
    }
}
