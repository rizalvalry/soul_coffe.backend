<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's clock-in for one operating day. Written only by AttendanceService.
 */
class Attendance extends Model
{
    protected $fillable = [
        'operating_date',
        'user_id',
        'role',
        'clocked_in_at',
        // Where the clock-in was made, and under which rule it was allowed — see AbsenGeofence.
        'gps_lat',
        'gps_lng',
        'distance_m',
        'geofence_basis',
    ];

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'role' => Role::class,
            'clocked_in_at' => 'datetime',
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'distance_m' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
