<?php

namespace App\Enums;

enum KitchenRejectReason: string
{
    case DROPPED = 'DROPPED';
    case PACKAGING = 'PACKAGING';
    case QUALITY = 'QUALITY';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::DROPPED => 'Jatuh / tumpah',
            self::PACKAGING => 'Kemasan rusak',
            self::QUALITY => 'Kualitas tidak layak',
            self::OTHER => 'Lainnya',
        };
    }

    public static function labelFor(string $code): string
    {
        return self::tryFrom($code)?->label() ?? self::OTHER->label();
    }
}
