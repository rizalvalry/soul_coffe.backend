<?php

namespace App\Models;

use App\Enums\WasteReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The mutable, audited classification of an immutable waste ledger row. */
class WasteClassification extends Model
{
    protected $fillable = ['stock_ledger_id', 'reason_code'];

    protected function casts(): array
    {
        return ['reason_code' => WasteReason::class];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockLedger::class, 'stock_ledger_id');
    }
}
