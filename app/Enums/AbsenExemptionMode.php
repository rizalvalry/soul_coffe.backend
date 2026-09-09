<?php

namespace App\Enums;

/**
 * `attendance_exemptions.mode` — the two shapes a legitimate exception takes.
 *
 * Deliberately only two. A free-form radius per exemption was the obvious third option and was
 * left out: it turns every exemption into a small negotiation about metres, and the two cases
 * that actually occur are "they are at their selling point, not the kitchen" and "today does not
 * fit any pin we hold".
 */
enum AbsenExemptionMode: string
{
    /** Clock in at the cart's own selling location instead of the kitchen. */
    case SELLING_LOCATION = 'selling_location';

    /** No geofence at all — events, a mess far from the kitchen, a week-long acara. */
    case ANYWHERE = 'anywhere';

    public function label(): string
    {
        return match ($this) {
            self::SELLING_LOCATION => 'Absen di titik jualan',
            self::ANYWHERE => 'Bebas lokasi',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SELLING_LOCATION => 'Boleh absen dari lokasi berjualan gerobak ini, bukan dari Dapur Pusat.',
            self::ANYWHERE => 'Boleh absen dari mana saja. Pakai untuk event, car free day, atau mess yang jauh.',
        };
    }
}
