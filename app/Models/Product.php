<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'unit',
        'is_sellable',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function priceVersions(): HasMany
    {
        return $this->hasMany(ProductPriceVersion::class, 'product_id');
    }

    public function dailyTargets(): HasMany
    {
        return $this->hasMany(DailyTarget::class, 'product_id');
    }

    public function dailyAllocationLines(): HasMany
    {
        return $this->hasMany(DailyAllocationLine::class, 'product_id');
    }

    public function refillRequestLines(): HasMany
    {
        return $this->hasMany(RefillRequestLine::class, 'product_id');
    }

    public function stockLedgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'product_id');
    }

    /**
     * The price version in effect right now — the "current" price. §12: price
     * versions are never edited in place, only appended.
     */
    public function currentPriceVersion(): ?ProductPriceVersion
    {
        return $this->priceVersions()
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->first();
    }
}
