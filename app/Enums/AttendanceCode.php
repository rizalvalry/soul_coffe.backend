<?php

namespace App\Enums;

/**
 * The five cell values of the monthly absensi sheet — see
 * docs/screenshots/bisnisproses/excel-absensi.jpeg, which this module reproduces.
 *
 * Reverse-engineered from that sheet's own summary columns rather than assumed: the "Hadir (M)",
 * "Libur (L)", "Sakit (S)" headers name the codes they count, and the orange "Berangkat Siang /
 * Tidak Target" column is the only one left for T.
 *
 * Only PRESENT can be produced by the app itself (a clock-in). T may be suggested after Closing;
 * manual marks still take precedence over clock-ins and suggestions.
 */
enum AttendanceCode: string
{
    case PRESENT = 'M';
    case LATE = 'T';
    case DAY_OFF = 'L';
    case SICK = 'S';
    case ABSENT = 'A';

    public function label(): string
    {
        return match ($this) {
            self::PRESENT => 'Masuk (hadir)',
            self::LATE => 'Berangkat siang / tidak target',
            self::DAY_OFF => 'Libur',
            self::SICK => 'Sakit',
            self::ABSENT => 'Mangkir',
        };
    }
}
