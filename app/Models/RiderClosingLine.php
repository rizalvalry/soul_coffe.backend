<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderClosingLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'rider_closing_id', 'product_id', 'qty_in', 'qty_carry', 'qty_remaining',
        'qty_wasted', 'qty_sold', 'qty_sale_posted', 'qty_waste_posted',
        'unit_price_minor', 'unit_cost_minor', 'subtotal_minor',
    ];

    protected function casts(): array
    {
        return [
            'qty_in' => 'integer', 'qty_carry' => 'integer', 'qty_remaining' => 'integer',
            'qty_wasted' => 'integer', 'qty_sold' => 'integer',
            'qty_sale_posted' => 'integer', 'qty_waste_posted' => 'integer',
            'unit_price_minor' => 'integer', 'unit_cost_minor' => 'integer',
            'subtotal_minor' => 'integer',
        ];
    }
}
