<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectSaleLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'direct_sale_id',
        'product_id',
        'qty',
        'unit_price_minor',
        'subtotal_minor',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price_minor' => 'integer',
            'subtotal_minor' => 'integer',
        ];
    }

    public function directSale(): BelongsTo
    {
        return $this->belongsTo(DirectSale::class, 'direct_sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
