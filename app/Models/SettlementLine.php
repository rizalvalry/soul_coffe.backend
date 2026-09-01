<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'settlement_id',
        'product_id',
        'qty_issued',
        'qty_sold',
        'qty_remaining',
        'qty_wasted',
        'variance_qty',
    ];

    protected function casts(): array
    {
        return [
            'qty_issued' => 'integer',
            'qty_sold' => 'integer',
            'qty_remaining' => 'integer',
            'qty_wasted' => 'integer',
            'variance_qty' => 'integer',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'settlement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
