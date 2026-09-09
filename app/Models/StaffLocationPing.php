<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded position of a staff member's phone. Append-only; see the migration for why.
 */
class StaffLocationPing extends Model
{
    protected $fillable = [
        'user_id',
        'operating_date',
        'recorded_at',
        'captured_at',
        'lat',
        'lng',
        'accuracy_m',
        'battery_pct',
        'is_moving',
        'source',
        'cart_id',
        'location_id',
        'device_id',
    ];

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'recorded_at' => 'datetime',
            'captured_at' => 'datetime',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'accuracy_m' => 'integer',
            'battery_pct' => 'integer',
            'is_moving' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
