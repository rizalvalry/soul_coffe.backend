<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'operating_date',
        'cart_id',
        'staff_id',
        'kitchen_id',
        'barista_id',
        'location_id',
        'status',
        'is_correction',
        'correction_reason',
        'over_target_pct',
        'finance_approval_id',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'status' => AllocationStatus::class,
            'is_correction' => 'boolean',
            'over_target_pct' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(CentralKitchen::class, 'kitchen_id');
    }

    public function barista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barista_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** The FINANCE user who approved an over-target allocation, if any. */
    public function financeApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_approval_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DailyAllocationLine::class, 'allocation_id');
    }
}
