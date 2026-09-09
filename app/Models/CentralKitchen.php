<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentralKitchen extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        // Where the kitchen physically is, and how close somebody must be to clock in there.
        // Null coordinates mean no geofence at this kitchen — see AbsenGeofence.
        'lat',
        'lng',
        'geofence_m',
        'open_at',
        'close_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'geofence_m' => 'integer',
        ];
    }

    public function baristas(): HasMany
    {
        return $this->hasMany(User::class, 'kitchen_id');
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class, 'kitchen_id');
    }

    public function dailyAllocations(): HasMany
    {
        return $this->hasMany(DailyAllocation::class, 'kitchen_id');
    }

    public function refillRequests(): HasMany
    {
        return $this->hasMany(RefillRequest::class, 'kitchen_id');
    }

    public function stockLedgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'kitchen_id');
    }
}
