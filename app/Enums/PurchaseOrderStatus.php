<?php

namespace App\Enums;

/**
 * `purchase_orders.status`. A strict one-way lifecycle: DRAFT -> ORDERED -> RECEIVED, with a
 * side exit to CANCELLED from DRAFT or ORDERED only — never from RECEIVED, because a received
 * order has already posted PURCHASE_IN to the stock ledger, and undoing a posted fact is a Stock
 * Opname's job (see PurchaseOrderService::cancel()).
 */
enum PurchaseOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case ORDERED = 'ORDERED';
    case RECEIVED = 'RECEIVED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ORDERED => 'Dipesan',
            self::RECEIVED => 'Diterima',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isOrdered(): bool
    {
        return $this === self::ORDERED;
    }

    public function isReceived(): bool
    {
        return $this === self::RECEIVED;
    }
}
