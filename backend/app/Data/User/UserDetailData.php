<?php

namespace App\Data\User;

use App\Data\BaseData;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Public representation of a user account.
 */
#[MapName(SnakeCaseMapper::class)]
class UserDetailData extends BaseData
{
    public string $id;

    public string $name;

    public string $email;

    public ?CarbonImmutable $createdAt = null;
}
