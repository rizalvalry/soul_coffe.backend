<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * R6 — APPEND ONLY. Stock is a projection over SUM(qty_delta), never a mutable
 * counter. Never call update()/delete() on this model or its query builder;
 * a correction is a new compensating row (e.g. an ADJUSTMENT with the
 * opposite-sign qty_delta), not an edit of history.
 */
class StockLedger extends Model
{
    use HasFactory;

    protected $table = 'stock_ledger';

    const UPDATED_AT = null;

    protected $fillable = [
        'location_type',
        'location_id',
        'product_id',
        'movement_type',
        'qty_delta',
        'ref_type',
        'ref_id',
        'actor_id',
        'kitchen_id',
    ];

    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'qty_delta' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(CentralKitchen::class, 'kitchen_id');
    }

    /**
     * Projected stock for one (location_type, location_id, product_id) as of now.
     */
    public static function projectedStock(string $locationType, int $locationId, int $productId): int
    {
        return (int) static::query()
            ->where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->sum('qty_delta');
    }
}
