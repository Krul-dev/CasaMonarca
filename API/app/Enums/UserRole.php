<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Coordinator = 'coordinator';
    case NonCoordinator = 'non_coordinator';
    case Volunteer = 'volunteer';

    public static function default(): self
    {
        return self::NonCoordinator;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
