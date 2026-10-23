<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAdvance extends Model
{
    protected $fillable = [
        'request_key', 'user_id', 'type_id', 'start_date', 'principal_minor', 'reason',
        'installment_kind', 'installment_minor', 'source_kind', 'cash_account_id',
        'status', 'cancel_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'principal_minor' => 'integer',
            'installment_minor' => 'integer', 'user_id' => 'integer', 'type_id' => 'integer',
            'cash_account_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvanceType::class, 'type_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceMovement::class, 'advance_id');
    }

    public function balanceMinor(): int
    {
        return (int) $this->movements()->sum('amount_minor');
    }
}
