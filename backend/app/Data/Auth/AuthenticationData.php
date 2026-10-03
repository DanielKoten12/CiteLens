<?php

namespace App\Data\Auth;

use App\Data\BaseData;
use App\Data\User\UserDetailData;
use App\Models\User;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Token issuance payload shared by `POST /auth/register` and
 * `POST /auth/login` (`docs/API_SPEC.md` §3).
 */
#[MapName(SnakeCaseMapper::class)]
class AuthenticationData extends BaseData
{
    public function __construct(
        public UserDetailData $user,
        public string $token,
        public string $tokenType = 'Bearer',
    ) {}

    public static function forUser(User $user, string $token): self
    {
        return new self(
            user: UserDetailData::from($user),
            token: $token,
        );
    }
}
