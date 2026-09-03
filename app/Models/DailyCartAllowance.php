<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cart's operational allowance for one operating day — see the migration for why this
 * records only the fact and not its accounting treatment.
 */
class DailyCartAllowance extends Model
{
    protected $fillable = [
        'operating_date',
        'cart_id',
        'amount_minor',
        'is_edited',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'amount_minor' => 'integer',
            'is_edited' => 'boolean',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
