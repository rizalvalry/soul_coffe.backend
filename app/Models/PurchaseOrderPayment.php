<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseOrderPayment extends Model
{
    protected $fillable = ['uuid', 'purchase_order_id', 'cash_account_id', 'paid_on', 'amount_minor', 'paid_by'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount_minor' => 'integer'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function journal(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'source_id')
            ->where('source_type', 'purchase_order_payment')->where('purpose', 'PAYMENT');
    }
}
