<?php

namespace App\Models;

use App\Enums\AbsenExemptionMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Permission for one cart's staff to clock in away from the Dapur Pusat. See the migration for
 * why this exists rather than simply loosening the rule.
 */
class AttendanceExemption extends Model
{
    protected $fillable = [
        'cart_id',
        'mode',
        'effective_from',
        'effective_until',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'mode' => AbsenExemptionMode::class,
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * In force on a given day. An open-ended exemption (no end date) stays in force until
     * somebody deletes it, which is the honest reading of "sampai acaranya selesai".
     */
    public function scopeActiveOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date->toDateString());
            });
    }
}
