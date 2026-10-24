<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationPriceVersion extends Model
{
    protected $fillable = ['product_id', 'location_id', 'sell_price_minor', 'effective_from'];

    protected function casts(): array
    {
        return ['sell_price_minor' => 'integer', 'effective_from' => 'date'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
