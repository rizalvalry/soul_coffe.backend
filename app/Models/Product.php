<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    use HasFactory;

    public function save(array $options = []): bool
    {
        if (! $this->exists || ! $this->isDirty('unit')) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options): bool {
            // SaleService holds this same row lock until sale_lines are inserted.
            DB::table($this->getTable())->where('id', $this->getKey())->lockForUpdate()->first(['id']);
            if (DB::table('sale_lines')->where('product_id', $this->getKey())->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages([
                    'unit' => 'Satuan produk yang sudah pernah dijual tidak dapat diubah.',
                ]);
            }

            return parent::save($options);
        });
    }

    protected $fillable = [
        'code',
        'name',
        'image_path',
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

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'product_id');
    }

    /**
     * The price version in effect right now — the "current" price. §12: price
     * versions are never edited in place, only appended.
     */
    public function currentPriceVersion(): ?ProductPriceVersion
    {
        return $this->priceVersionAt(now());
    }

    /** Select the latest effective version, with insertion order breaking timestamp ties. */
    public function priceVersionAt(Carbon $at, bool $knownAtTime = false): ?ProductPriceVersion
    {
        $versions = $this->priceVersions()->where('effective_from', '<=', $at);

        if ($knownAtTime) {
            $second = $at->copy()->startOfSecond();
            $known = (clone $versions)
                ->where('created_at', '<', $second)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            // A same-second version may have been entered before OR after the sale. Only one
            // that could outrank the last known version makes historical HPP ambiguous.
            if ((clone $versions)->where('created_at', '>=', $second)
                ->where('created_at', '<', $second->copy()->addSecond())
                ->when($known, fn ($query) => $query->where('effective_from', '>=', $known->effective_from))
                ->exists()) {
                return null;
            }

            return $known;
        }

        return $versions
            ->orderByDesc('effective_from')
            // Tie-break on insertion order. Without it two versions sharing an effective_from
            // leave "the current price" up to whatever order the database happens to return —
            // and R10 makes that collision ordinary, not exotic: correcting a price is not an
            // edit, it is another row, and a correction entered seconds later carries the same
            // timestamp. The later row is the one that was meant to win.
            ->orderByDesc('id')
            ->first();
    }
}
