<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public static function default(): self
    {
        return self::Active;
    }
}
