<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One article in the in-app feed. Authored by CONTENT_CREATOR in the Filament panel.
 *
 * The visibility rules live here, in `scopeVisibleTo`, and nowhere else. Every reader — the API,
 * the slider, a future digest — goes through that one scope, so "published" can never come to
 * mean something slightly different in two places.
 */
class NewsPost extends Model
{
    use HasFactory;

    public const REACTIONS = ['api', 'mantap', 'semangat', 'bingung'];

    protected $fillable = [
        'title',
        'slug',
        'kicker',
        'excerpt',
        'body',
        'cover_path',
        'tags',
        'accent_color',
        'audience_roles',
        'status',
        'is_highlighted',
        'sort_order',
        'published_at',
        'expires_at',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'audience_roles' => 'array',
            'is_highlighted' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(NewsPostEngagement::class);
    }

    /**
     * The single definition of "this person can see this post right now".
     *
     * A post is live when it is published, its publish time has arrived, and it has not expired.
     * Audience is a whitelist: an empty or absent `audience_roles` means everyone, which keeps
     * the common case (a general announcement) free of ceremony.
     */
    public function scopeVisibleTo(Builder $query, Role $role): Builder
    {
        return $query
            ->where('status', 'published')
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function (Builder $q) use ($role): void {
                $q->whereNull('audience_roles')
                    ->orWhereJsonLength('audience_roles', 0)
                    ->orWhereJsonContains('audience_roles', $role->value);
            });
    }

    /**
     * Feed order: highlighted posts first, then the creator's own `sort_order`, then newest.
     *
     * `sort_order` ascending is what makes the panel's drag-to-reorder mean what it looks like it
     * means. The `published_at` tiebreak stops two posts sharing an order from swapping places
     * between requests, which would make the slider appear to shuffle itself at random.
     */
    public function scopeFeedOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('is_highlighted')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null;
    }

    /**
     * Attaches `reaction_counts` as `{"api": 12, "mantap": 4}` to every row in one extra query.
     *
     * One aggregate for the whole page, not one per post: the feed renders up to forty cards and
     * a per-card count would be forty round trips to draw a row of small numbers.
     */
    public function scopeWithReactionCounts(Builder $query): Builder
    {
        return $query->afterQuery(function ($posts): void {
            $ids = $posts->pluck('id');
            if ($ids->isEmpty()) {
                return;
            }

            $counts = NewsPostEngagement::query()
                ->whereIn('news_post_id', $ids)
                ->whereNotNull('reaction')
                ->groupBy('news_post_id', 'reaction')
                ->selectRaw('news_post_id, reaction, count(*) as total')
                ->get()
                ->groupBy('news_post_id')
                ->map(fn ($rows) => $rows->pluck('total', 'reaction'));

            foreach ($posts as $post) {
                $post->reaction_counts = $counts->get($post->id, collect());
            }
        });
    }

    /** The single-record equivalent of the scope above, for `show()`. */
    public function loadReactionCounts(): static
    {
        $this->reaction_counts = NewsPostEngagement::query()
            ->where('news_post_id', $this->id)
            ->whereNotNull('reaction')
            ->groupBy('reaction')
            ->selectRaw('reaction, count(*) as total')
            ->pluck('total', 'reaction');

        return $this;
    }
}
