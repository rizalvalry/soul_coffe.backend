<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAsset extends Model
{
    protected $fillable = [
        'uuid', 'name', 'category_id', 'acquired_on', 'cost_minor', 'useful_life_months',
        'residual_minor', 'location', 'cart_id', 'status', 'acquisition_mode',
        'funding_account_id', 'created_by', 'disposed_on', 'disposal_proceeds_minor', 'disposal_account_id',
    ];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date', 'disposed_on' => 'date', 'cost_minor' => 'integer',
            'residual_minor' => 'integer', 'useful_life_months' => 'integer',
            'disposal_proceeds_minor' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FixedAssetCategory::class, 'category_id');
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
