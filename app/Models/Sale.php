<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sale from one cart. Written only by SaleService — see the migration for why this is per
 * transaction rather than a daily total.
 */
class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'operating_date',
        'cart_id',
        'staff_id',
        'location_id',
        'occurred_at',
        'total_qty',
        'total_amount_minor',
        'payment_method',
        'gps_lat',
        'gps_lng',
        'gps_unavailable',
        'is_suspect',
        'suspect_reason',
        'note',
        'device_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'occurred_at' => 'datetime',
            'total_qty' => 'integer',
            'total_amount_minor' => 'integer',
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'gps_unavailable' => 'boolean',
            'is_suspect' => 'boolean',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class, 'sale_id');
    }
}
