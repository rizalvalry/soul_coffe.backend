<?php

namespace App\Models;

use App\Enums\PartnerAttendanceCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One filled-in cell of the monthly absensi sheet. */
class PartnerAttendanceEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_id',
        'entry_date',
        'code',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'code' => PartnerAttendanceCode::class,
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
