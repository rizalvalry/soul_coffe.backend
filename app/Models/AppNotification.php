<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `notifications` table (§8/§12). Named AppNotification, not Notification,
 * to avoid any confusion with Laravel's built-in
 * Illuminate\Notifications\DatabaseNotification, which this is not — the
 * columns (user_id/event_id/type/payload_json) are unrelated to that system's
 * notifiable_type/notifiable_id shape, and User no longer uses the Notifiable
 * trait (see App\Models\User) for exactly this reason.
 */
class AppNotification extends Model
{
    use HasFactory;

    protected $table = 'notifications';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'event_id',
        'type',
        'payload_json',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
