<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThrSettingVersion extends Model
{
    protected $fillable = [
        'effective_from', 'component_type_ids', 'minimum_months', 'full_months',
        'prorata_denominator', 'reminder_days', 'reserve_enabled', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date', 'component_type_ids' => 'array',
            'minimum_months' => 'integer', 'full_months' => 'integer',
            'prorata_denominator' => 'integer', 'reminder_days' => 'integer',
            'reserve_enabled' => 'boolean',
        ];
    }
}
