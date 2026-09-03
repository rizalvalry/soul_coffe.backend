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
    ];

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'role' => Role::class,
            'clocked_in_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
