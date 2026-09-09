<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'plate',
        'status',
        'high_volume_zone',
        'kitchen_id',
    ];

    protected function casts(): array
    {
        return [
            'high_volume_zone' => 'boolean',
        ];
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(CentralKitchen::class, 'kitchen_id');
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffAssignment::class, 'cart_id');
    }

    public function dailyTargets(): HasMany
    {
        return $this->hasMany(DailyTarget::class, 'cart_id');
    }

    public function dailyAllocations(): HasMany
    {
        return $this->hasMany(DailyAllocation::class, 'cart_id');
    }

    public function refillRequests(): HasMany
    {
        return $this->hasMany(RefillRequest::class, 'cart_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'cart_id');
    }
}
