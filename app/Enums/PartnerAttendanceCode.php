<?php

namespace App\Enums;

/**
 * The four cell values of the monthly absensi sheet — see docs/screenshots/bisnisproses/
 * excel-absensi.jpeg, which this module reproduces.
 *
 * Reverse-engineered from that sheet's own summary columns rather than assumed: the "Hadir (M)",
 * "Libur (L)", "Sakit (S)" headers name the codes they count, and the orange "Berangkat Siang /
 * Tidak Target" column is the only one left for T.
 */
enum PartnerAttendanceCode: string
{
    case PRESENT = 'M';
    case LATE = 'T';
    case DAY_OFF = 'L';
    case SICK = 'S';

    public function label(): string
    {
        return match ($this) {
            self::PRESENT => 'Masuk (Hadir)',
            self::LATE => 'Berangkat Siang / Tidak Target',
            self::DAY_OFF => 'Libur',
            self::SICK => 'Sakit',
        };
    }

    /** Cell colours, matching the sheet: L red, T orange, S blue, M left plain. */
    public function background(): string
    {
        return match ($this) {
            self::PRESENT => '#dcfce7',
            self::LATE => '#f59e0b',
            self::DAY_OFF => '#ef4444',
            self::SICK => '#38bdf8',
        };
    }

    public function foreground(): string
    {
        return match ($this) {
            self::PRESENT => '#166534',
            self::LATE, self::DAY_OFF, self::SICK => '#ffffff',
        };
    }
}
