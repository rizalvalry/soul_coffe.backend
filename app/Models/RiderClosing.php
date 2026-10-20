<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiderClosing extends Model
{
    protected $fillable = [
        'operating_date', 'cart_id', 'rider_id', 'total_minor', 'cogs_minor',
        'qris_minor', 'transfer_minor', 'cash_minor', 'gps_lat', 'gps_lng',
        'gps_unavailable', 'closed_at', 'closing_basis', 'closing_distance_m', 'outside_closing_point',
    ];

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'closed_at' => 'datetime',
            'total_minor' => 'integer',
            'cogs_minor' => 'integer',
            'qris_minor' => 'integer',
            'transfer_minor' => 'integer',
            'cash_minor' => 'integer',
            'gps_unavailable' => 'boolean',
            'closing_distance_m' => 'integer',
            'outside_closing_point' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RiderClosingLine::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
