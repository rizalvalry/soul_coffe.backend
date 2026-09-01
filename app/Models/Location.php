<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'lat',
        'lng',
        'geofence_m',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffAssignment::class, 'location_id');
    }

    public function dailyTargets(): HasMany
    {
        return $this->hasMany(DailyTarget::class, 'location_id');
    }

    public function dailyAllocations(): HasMany
    {
        return $this->hasMany(DailyAllocation::class, 'location_id');
    }
}
