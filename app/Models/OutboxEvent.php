<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Transactional outbox (§12) — written in the same DB transaction as the state
 * change that produced it, published by a worker. Ensures a Reverb restart
 * never silently drops a realtime notification.
 */
class OutboxEvent extends Model
{
    use HasFactory;

    protected $table = 'outbox_events';

    const UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'name',
        'payload_json',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
