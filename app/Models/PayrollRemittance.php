<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRemittance extends Model
{
    protected $fillable = [
        'month', 'kind', 'program', 'paid_on', 'amount_minor', 'receipt_number',
        'account_id', 'idempotency_key', 'status', 'created_by', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return ['month' => 'date', 'paid_on' => 'date', 'amount_minor' => 'integer'];
    }
}
