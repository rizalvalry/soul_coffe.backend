<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClearingAllocation extends Model
{
    public $timestamps = false;

    protected $fillable = ['clearing_receipt_id', 'sale_date', 'effective_on', 'amount_minor'];

    protected function casts(): array
    {
        return ['sale_date' => 'date', 'effective_on' => 'date', 'amount_minor' => 'integer'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ClearingReceipt::class, 'clearing_receipt_id');
    }
}
