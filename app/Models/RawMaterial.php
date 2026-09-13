<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'unit',
        'reorder_point',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'reorder_point' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class, 'raw_material_id');
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'raw_material_id');
    }

    public function stockLedgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'raw_material_id');
    }
}
