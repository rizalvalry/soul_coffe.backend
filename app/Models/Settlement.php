<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'operating_date',
        'cart_id',
        'staff_id',
        'status',
        'cash_minor',
        'qris_minor',
        'transfer_minor',
        'declared_total_minor',
        'expected_total_minor',
        'expected_cash_minor',
        'expected_qris_minor',
        'expected_transfer_minor',
        'expected_cogs_minor',
        'expected_stale',
        'stale_delta_minor',
        'variance_minor',
        'variance_reason',
        'reconciled_by',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'cash_minor' => 'integer',
            'qris_minor' => 'integer',
            'transfer_minor' => 'integer',
            'declared_total_minor' => 'integer',
            'expected_total_minor' => 'integer',
            'expected_cash_minor' => 'integer',
            'expected_qris_minor' => 'integer',
            'expected_transfer_minor' => 'integer',
            'expected_cogs_minor' => 'integer',
            'expected_stale' => 'boolean',
            'stale_delta_minor' => 'integer',
            'variance_minor' => 'integer',
            'reconciled_at' => 'datetime',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SettlementLine::class, 'settlement_id');
    }
}
