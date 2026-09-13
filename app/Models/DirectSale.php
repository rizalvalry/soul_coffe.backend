<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One walk-in sale at the central kitchen/office. Written only by DirectSaleService — see the
 * migration for why this is a separate table from `sales` rather than a row in it.
 */
class DirectSale extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'cart_id',
        'recorded_by',
        'occurred_at',
        'total_qty',
        'total_amount_minor',
        'payment_method',
        'note',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'total_qty' => 'integer',
            'total_amount_minor' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DirectSaleLine::class, 'direct_sale_id');
    }
}
