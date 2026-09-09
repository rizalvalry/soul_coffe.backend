<?php

namespace App\Enums;

/**
 * `delivery_incidents.status`.
 *
 * Three values, because there are exactly three outcomes an accident can have: it is waiting for
 * a decision, the run was called off, or the surviving cups went out anyway. A fourth value for
 * "rejected report" was deliberately not added — a report that turns out to be mistaken is
 * resolved with a decision and a note, so the photo and the note both stay readable afterwards.
 */
enum IncidentStatus: string
{
    case REPORTED = 'REPORTED';
    case RESOLVED_CANCELLED = 'RESOLVED_CANCELLED';
    case RESOLVED_PARTIAL = 'RESOLVED_PARTIAL';

    public function label(): string
    {
        return match ($this) {
            self::REPORTED => 'Menunggu keputusan',
            self::RESOLVED_CANCELLED => 'Pengantaran dibatalkan',
            self::RESOLVED_PARTIAL => 'Lanjut sebagian',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::REPORTED;
    }
}
