<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefillRequestLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'refill_request_id',
        'product_id',
        'qty_requested',
        'qty_approved',
        'qty_prepared',
        'qty_received',
        'unit_cost_minor',
        'line_cost_minor',
    ];

    protected function casts(): array
    {
        return [
            'qty_requested' => 'integer',
            'qty_approved' => 'integer',
            'qty_prepared' => 'integer',
            'qty_received' => 'integer',
            'unit_cost_minor' => 'integer',
            'line_cost_minor' => 'integer',
        ];
    }

    public function refillRequest(): BelongsTo
    {
        return $this->belongsTo(RefillRequest::class, 'refill_request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
