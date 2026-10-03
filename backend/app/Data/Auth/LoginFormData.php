<?php

namespace App\Data\Auth;

use App\Data\BaseData;

class LoginFormData extends BaseData
{
    public string $email;
    public string $password;
}
