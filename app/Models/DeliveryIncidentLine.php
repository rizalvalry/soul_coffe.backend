<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many cups of one product were lost in one incident.
 */
class DeliveryIncidentLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'delivery_incident_id',
        'refill_request_line_id',
        'product_id',
        'qty_damaged',
    ];

    protected function casts(): array
    {
        return [
            'qty_damaged' => 'integer',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DeliveryIncident::class, 'delivery_incident_id');
    }

    public function refillLine(): BelongsTo
    {
        return $this->belongsTo(RefillRequestLine::class, 'refill_request_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
