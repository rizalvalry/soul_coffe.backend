<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClearingReceipt extends Model
{
    protected $fillable = [
        'uuid', 'received_on', 'amount_minor', 'mdr_minor', 'sale_dates_json',
        'bank_account_id', 'recorded_by', 'status', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date', 'amount_minor' => 'integer', 'mdr_minor' => 'integer',
            'sale_dates_json' => 'array', 'voided_at' => 'datetime',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ClearingAllocation::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
