<?php

namespace App\Data\Auth;

use App\Data\BaseData;

class RegisterFormData extends BaseData
{
    public string $name;
    public string $email;
    public string $password;
}
