<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_id',
        'product_id',
        'qty_brewed',
        'recipe_id',
        'recipe_cost_minor',
    ];

    protected function casts(): array
    {
        return [
            'qty_brewed' => 'integer',
            'recipe_cost_minor' => 'integer',
        ];
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class, 'production_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }
}
