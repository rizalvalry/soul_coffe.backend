<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "lupa PIN" request awaiting (or after) an Administrator's decision.
 */
class PinResetRequest extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_RESOLVED = 'RESOLVED';

    public const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'user_id',
        'phone_e164',
        'email',
        'password_verified',
        'status',
        'requested_ip',
        'attempts',
        'resolved_by',
        'resolved_at',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'password_verified' => 'boolean',
            'attempts' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
