<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'supplier_id',
        'kitchen_id',
        'status',
        'ordered_at',
        'received_at',
        'created_by',
        'received_by',
        'payment_method',
        'cash_account_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(CentralKitchen::class, 'kitchen_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class);
    }

    public function receiptJournal(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'source_id')
            ->where('source_type', 'purchase_order')->where('purpose', 'RECEIPT');
    }

    public function unpaidMinor(): int
    {
        if (! $this->status->isReceived() || $this->payment_method !== 'CREDIT') {
            return 0;
        }

        $receipt = $this->receiptJournal()->whereDoesntHave('reversal')->first();
        if (! $receipt) {
            return 0;
        }

        $received = (int) $receipt->lines()->sum('debit_minor');
        $paid = (int) $this->payments()->whereHas('journal', fn ($query) => $query->whereDoesntHave('reversal'))
            ->sum('amount_minor');

        return max(0, $received - $paid);
    }
}
