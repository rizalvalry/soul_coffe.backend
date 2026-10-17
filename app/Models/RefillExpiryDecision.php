<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefillExpiryDecision extends Model
{
    protected $fillable = [
        'refill_request_id', 'decided_by', 'outcome', 'reason', 'recognized_lines',
        'confirmed_prepared_lines', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'recognized_lines' => 'array',
            'confirmed_prepared_lines' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function refillRequest(): BelongsTo
    {
        return $this->belongsTo(RefillRequest::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
