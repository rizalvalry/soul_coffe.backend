<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'version',
        'is_active',
        'effective_from',
        'created_by',
        'computed_cost_minor',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
            'computed_cost_minor' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class, 'recipe_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function productionLines(): HasMany
    {
        return $this->hasMany(ProductionLine::class, 'recipe_id');
    }
}
