<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitchenReject extends Model
{
    protected $fillable = ['kitchen_id', 'actor_id', 'photo_media_id', 'operating_date',
        'reason_code', 'note', 'status', 'reviewed_by', 'reviewed_at', 'correction_reason'];

    protected function casts(): array
    {
        return ['operating_date' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(KitchenRejectLine::class);
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(CentralKitchen::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'photo_media_id');
    }
}
