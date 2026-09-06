<?php

namespace App\Observers;

use App\Enums\Role;
use App\Models\NewsPost;
use App\Models\User;
use App\Services\EventPublisher;

/**
 * Announces a news post the moment it goes live.
 *
 * An observer rather than a hook on the two Filament pages: `CreateNewsPost` and `EditNewsPost`
 * are both able to publish, and a third write path (a command, a seeder, a future scheduler) would
 * silently skip a notification wired into only those two. The model is the one place every path
 * passes through.
 *
 * Fires exactly once per post, on the transition INTO a live state. The guards below are what make
 * that true:
 *   - `status` must be `published` now and must not have been `published` a moment ago;
 *   - `published_at` must not be in the future — a scheduled post is not live yet, and buzzing
 *     staff about an article they cannot open would be worse than silence;
 *   - `expires_at` must not already have passed.
 */
class NewsPostObserver
{
    public function __construct(private readonly EventPublisher $events) {}

    public function created(NewsPost $post): void
    {
        if ($this->isLive($post)) {
            $this->announce($post);
        }
    }

    public function updated(NewsPost $post): void
    {
        if (! $post->wasChanged('status')) {
            return;
        }

        // getOriginal() holds the pre-save value, so re-saving an already-published post (an
        // edited typo, a reorder) changes nothing here and sends nothing.
        if ($post->getOriginal('status') === 'published') {
            return;
        }

        if ($this->isLive($post)) {
            $this->announce($post);
        }
    }

    private function isLive(NewsPost $post): bool
    {
        if ($post->status !== 'published') {
            return false;
        }

        if ($post->published_at !== null && $post->published_at->isFuture()) {
            return false;
        }

        return $post->expires_at === null || $post->expires_at->isFuture();
    }

    private function announce(NewsPost $post): void
    {
        $audience = $this->audienceRoles($post);

        $recipients = User::query()
            ->where('is_active', true)
            ->whereIn('role', array_map(fn (Role $role) => $role->value, $audience))
            ->pluck('id')
            ->all();

        if ($recipients === []) {
            return;
        }

        $this->events->publish(
            'NewsPostPublished',
            $post->kicker ? sprintf('%s · %s', $post->kicker, 'Kabar baru') : 'Kabar baru',
            $post->title,
            array_map(fn (Role $role) => 'role.'.$role->value, $audience),
            $recipients,
        );
    }

    /**
     * An empty or absent `audience_roles` means everyone, matching `NewsPost::scopeVisibleTo`.
     *
     * CONTENT_CREATOR is excluded even from "everyone": that role has no mobile API access at all
     * (Role::isOperational), so a push would point at an app it cannot sign into.
     *
     * @return array<int,Role>
     */
    private function audienceRoles(NewsPost $post): array
    {
        $configured = array_filter(
            array_map(
                fn ($value) => Role::tryFrom((string) $value),
                is_array($post->audience_roles) ? $post->audience_roles : [],
            ),
        );

        if ($configured === []) {
            $configured = Role::cases();
        }

        return array_values(array_filter($configured, fn (Role $role) => $role->isOperational()));
    }
}
