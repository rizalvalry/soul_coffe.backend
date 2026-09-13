<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product's counted quantity within a stock opname. See the migration for why
 * `system_qty_at_count` and `system_qty_at_apply`/`applied_delta` are kept as separate pairs
 * rather than one column each getting overwritten.
 */
class StockOpnameLine extends Model
{
    protected $fillable = [
        'stock_opname_id',
        'product_id',
        'system_qty_at_count',
        'counted_qty',
        'variance_qty',
        'system_qty_at_apply',
        'applied_delta',
    ];

    protected function casts(): array
    {
        return [
            'system_qty_at_count' => 'integer',
            'counted_qty' => 'integer',
            'variance_qty' => 'integer',
            'system_qty_at_apply' => 'integer',
            'applied_delta' => 'integer',
        ];
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
