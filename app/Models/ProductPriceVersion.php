<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'cost_price_minor',
        'sell_price_minor',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'cost_price_minor' => 'integer',
            'sell_price_minor' => 'integer',
            'effective_from' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
