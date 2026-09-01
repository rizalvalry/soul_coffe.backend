<?php

namespace App\Models;

use App\Enums\RefillStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefillStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'refill_status_history';

    const UPDATED_AT = null; // R8 audit row — append only

    protected $fillable = [
        'refill_request_id',
        'from_status',
        'to_status',
        'actor_id',
        'actor_role',
        'reason',
        'device_id',
        'gps_lat',
        'gps_lng',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => RefillStatus::class,
            'to_status' => RefillStatus::class,
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
        ];
    }

    public function refillRequest(): BelongsTo
    {
        return $this->belongsTo(RefillRequest::class, 'refill_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
