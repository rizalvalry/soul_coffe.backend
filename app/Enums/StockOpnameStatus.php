<?php

namespace App\Enums;

/**
 * `stock_opnames.status`. See the migration for why there are exactly three: a count that has
 * not yet touched the ledger (DRAFT), one that has and can never be un-applied (APPLIED), and one
 * abandoned before it touched anything (CANCELLED).
 */
enum StockOpnameStatus: string
{
    case DRAFT = 'DRAFT';
    case APPLIED = 'APPLIED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::APPLIED => 'Diterapkan',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }
}
