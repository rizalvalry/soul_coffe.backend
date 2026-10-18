<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = ['period_start', 'status', 'created_by', 'approved_by', 'approved_at', 'paid_at', 'payment_account_id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class, 'run_id');
    }
}
