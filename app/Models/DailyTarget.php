<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'location_id',
        'product_id',
        'target_qty',
        'weekday',
    ];

    protected function casts(): array
    {
        return [
            'target_qty' => 'integer',
            'weekday' => 'integer',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
