<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rider's report that cups were damaged in transit. See the migration for why this is its own
 * record rather than a refill status.
 */
class DeliveryIncident extends Model
{
    protected $fillable = [
        'uuid',
        'refill_request_id',
        'rider_id',
        'photo_media_id',
        'reported_at',
        'note',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'written_off_qty',
        'device_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'decided_at' => 'datetime',
            'status' => IncidentStatus::class,
            'written_off_qty' => 'integer',
        ];
    }

    public function refillRequest(): BelongsTo
    {
        return $this->belongsTo(RefillRequest::class, 'refill_request_id');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photo_media_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryIncidentLine::class, 'delivery_incident_id');
    }

    public function damagedQty(): int
    {
        return (int) $this->lines->sum('qty_damaged');
    }
}
