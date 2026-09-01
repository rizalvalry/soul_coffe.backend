<?php

namespace App\Enums;

/**
 * Refill request lifecycle (§6 of the business spec).
 * All transitions must go through RefillRequestStateMachine — no code assigns this
 * column directly (that rule belongs to the service layer, not this enum).
 */
enum RefillStatus: string
{
    case SUBMITTED = 'SUBMITTED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case PREPARING = 'PREPARING';
    case READY_TO_PICK = 'READY_TO_PICK';
    case PICKED_UP = 'PICKED_UP';
    case DELIVERED = 'DELIVERED';
    case CLOSED = 'CLOSED';

    /**
     * "Open" per R2 / the refill_requests.active_cart_id uniqueness trick:
     * SUBMITTED through PICKED_UP inclusive. DELIVERED and CLOSED are both
     * "not open" for this purpose, but only the four states below are treated
     * as terminal (active_cart_id nulled) — see RefillRequest::isTerminalStatus().
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::SUBMITTED, self::APPROVED, self::PREPARING,
            self::READY_TO_PICK, self::PICKED_UP => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Menunggu Approval Finance',
            self::APPROVED => 'Disetujui Finance',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
            self::EXPIRED => 'Kedaluwarsa',
            self::PREPARING => 'Sedang Disiapkan',
            self::READY_TO_PICK => 'Siap Diambil',
            self::PICKED_UP => 'Sedang Dikirim',
            self::DELIVERED => 'Terkirim',
            self::CLOSED => 'Selesai',
        };
    }
}
