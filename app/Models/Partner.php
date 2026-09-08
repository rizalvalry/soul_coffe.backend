<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person on the monthly absensi sheet — see the create_partners_table migration for why this
 * is not a `users` row.
 */
class Partner extends Model
{
    use HasFactory;

    protected $fillable = [
        'nik',
        'name',
        'role',
        'size',
        'monthly_libur_quota',
        'user_id',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'monthly_libur_quota' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Set when this partner also has a login; null for the ones who only exist on the sheet. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attendanceEntries(): HasMany
    {
        return $this->hasMany(PartnerAttendanceEntry::class, 'partner_id');
    }
}
