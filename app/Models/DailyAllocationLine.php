<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAllocationLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'allocation_id',
        'product_id',
        'target_qty',
        'qty_issued',
    ];

    protected function casts(): array
    {
        return [
            'target_qty' => 'integer',
            'qty_issued' => 'integer',
        ];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(DailyAllocation::class, 'allocation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
