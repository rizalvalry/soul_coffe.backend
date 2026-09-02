<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reader's relationship with one post — see the migration for why this table exists. */
class NewsPostEngagement extends Model
{
    protected $fillable = ['news_post_id', 'user_id', 'read_at', 'reaction'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(NewsPost::class, 'news_post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
