<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-day gate that lets Staff clock in. A missing row for today means closed — see the
 * migration docblock.
 */
class StaffAttendanceWindow extends Model
{
    protected $fillable = [
        'operating_date',
        'opened_by',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'opened_at' => 'datetime',
        ];
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
