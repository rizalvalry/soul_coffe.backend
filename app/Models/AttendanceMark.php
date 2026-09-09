<?php

namespace App\Models;

use App\Enums\AttendanceCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hand-entered cell of the monthly absensi sheet.
 *
 * Deliberately NOT the same table as `attendances`: that one records a fact the person created
 * themselves (they tapped absen, server clock, R16). This one records a decision somebody made
 * about them — libur, sakit, berangkat siang — or presence for a role that has no clock-in at
 * all. Keeping them apart is what lets the sheet show, per cell, whether the number came from
 * the person or from the office.
 */
class AttendanceMark extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entry_date',
        'code',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'code' => AttendanceCode::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
