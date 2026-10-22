<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationSetting extends Model
{
    protected $fillable = ['absen_radius_m', 'customer_radius_m', 'absen_requires_gps'];

    protected function casts(): array
    {
        return [
            'absen_radius_m' => 'integer',
            'customer_radius_m' => 'integer',
            'absen_requires_gps' => 'boolean',
            'version' => 'integer',
        ];
    }
}
