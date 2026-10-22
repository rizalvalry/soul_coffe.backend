<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThrRun extends Model
{
    protected $fillable = [
        'holiday_date', 'planned_payment_date', 'setting_version_id', 'status',
        'idempotency_key', 'created_by', 'approved_by', 'approved_at', 'paid_on', 'payment_account_id',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date', 'planned_payment_date' => 'date',
            'approved_at' => 'datetime', 'paid_on' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ThrItem::class, 'run_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(ThrSettingVersion::class, 'setting_version_id');
    }
}
