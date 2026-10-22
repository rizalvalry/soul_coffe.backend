<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CashTransaction extends Model
{
    protected $fillable = ['uuid', 'date', 'direction', 'cash_category_id', 'cash_account_id', 'amount_minor',
        'description', 'office_item_id', 'quantity', 'evidence_path', 'cart_id', 'recorded_by', 'status',
        'voided_by', 'voided_at', 'void_reason'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount_minor' => 'integer', 'quantity' => 'integer', 'voided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw ValidationException::withMessages(['transaction' => 'Transaksi kas tidak dapat dihapus.']));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CashCategory::class, 'cash_category_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function officeItem(): BelongsTo
    {
        return $this->belongsTo(OfficeItem::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
