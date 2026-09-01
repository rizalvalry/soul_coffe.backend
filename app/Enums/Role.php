<?php

namespace App\Enums;

/**
 * Employee roles, in the business-given priority order (§2 of the business spec).
 * The server decides the role on login; a role supplied by the client is ignored.
 */
enum Role: string
{
    case ADMINISTRATOR = 'ADMINISTRATOR';
    case FINANCE = 'FINANCE';
    case BARISTA = 'BARISTA';
    case RIDER = 'RIDER';
    case STAFF = 'STAFF';

    public function label(): string
    {
        return match ($this) {
            self::ADMINISTRATOR => 'Administrator',
            self::FINANCE => 'Finance',
            self::BARISTA => 'Barista',
            self::RIDER => 'Rider',
            self::STAFF => 'Staff',
        };
    }
}
