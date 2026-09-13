<?php

namespace App\Models;

use App\Enums\StockOpnameStatus;
use App\Services\StockLedgerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical stock count and its correction. Written only by StockOpnameService — see the
 * migration for the two-step draft/applied lifecycle.
 */
class StockOpname extends Model
{
    protected $fillable = [
        'uuid',
        'location_type',
        'location_id',
        'counted_date',
        'counted_at',
        'status',
        'reason',
        'created_by',
        'applied_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'counted_date' => 'date',
            'counted_at' => 'datetime',
            'status' => StockOpnameStatus::class,
            'applied_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockOpnameLine::class, 'stock_opname_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * The cart this opname counted, when its location is a cart. Relations cannot express "only
     * when a sibling column has this value" against the RELATED table (`carts` has no
     * `location_type` column), so the type check happens here rather than as a query constraint —
     * calling this on a kitchen-type row would otherwise coincidentally match whichever cart
     * happens to share that id.
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'location_id');
    }

    /** The kitchen this opname counted, when its location is a kitchen. Same caveat as cart(). */
    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(CentralKitchen::class, 'location_id');
    }

    /**
     * A human label for the location, regardless of type — what every list and notification
     * actually wants to show. Reads whichever relation actually applies to this row's
     * `location_type`, never both.
     */
    public function locationLabel(): string
    {
        if ($this->location_type === StockLedgerService::CART) {
            return 'Gerobak '.($this->cart?->code ?? '#'.$this->location_id);
        }

        return $this->kitchen?->name ?? 'Dapur #'.$this->location_id;
    }
}
