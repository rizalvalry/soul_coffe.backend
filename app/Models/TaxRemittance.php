<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRemittance extends Model
{
    protected $fillable = [
        'tax_type', 'paid_on', 'amount_minor', 'receipt_number', 'cash_account_id',
        'idempotency_key', 'status', 'created_by', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount_minor' => 'integer'];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }
}
