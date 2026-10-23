<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitchenRejectLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['kitchen_reject_id', 'product_id', 'qty', 'cost_minor'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
