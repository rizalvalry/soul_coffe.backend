<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsPostResource;
use App\Models\NewsPost;
use App\Models\NewsPostEngagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The in-app news feed (docs/04 §News).
 *
 * Read-only from the mobile client — posts are authored in the Filament panel. The only writes
 * here are a reader's own read receipt and reaction, which belong to the reader, not the post.
 *
 * Every query goes through `NewsPost::scopeVisibleTo()`, so a post that is scheduled, expired, or
 * addressed to another role is invisible at the QUERY level rather than filtered out of a
 * response — the same rule the rest of this API follows (docs/02 §2.1).
 */
class NewsController extends Controller
{
    private const FEED_LIMIT = 40;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $posts = NewsPost::query()
            ->visibleTo($user->role)
            ->when(
                $request->boolean('highlighted'),
                fn ($q) => $q->where('is_highlighted', true),
            )
            ->with([
                'author:id,name',
                // Constrained to this reader: the resource reads `engagements->first()` to report
                // "my reaction", and loading everyone's would be both wrong and enormous.
                'engagements' => fn ($q) => $q->where('user_id', $user->id),
            ])
            ->withReactionCounts()
            ->feedOrder()
            ->limit(self::FEED_LIMIT)
            ->get();

        return response()->json([
            'data' => $posts->map(fn (NewsPost $post) => new NewsPostResource($post)),
        ]);
    }

    public function show(Request $request, NewsPost $news): JsonResponse
    {
        $user = $request->user();

        // Re-checked rather than trusted: a direct id must obey exactly the same visibility rule
        // the list does, or the audience targeting is decoration.
        $visible = NewsPost::query()->visibleTo($user->role)->whereKey($news->id)->exists();
        abort_unless($visible, 404);

        $news->load([
            'author:id,name',
            'engagements' => fn ($q) => $q->where('user_id', $user->id),
        ]);
        $news->loadReactionCounts();

        return response()->json([
            'data' => (new NewsPostResource($news, withBody: true))->resolve($request),
        ]);
    }

    /** Marks the post read for the caller. Idempotent — opening it twice is still one read. */
    public function markRead(Request $request, NewsPost $news): JsonResponse
    {
        $user = $request->user();

        $visible = NewsPost::query()->visibleTo($user->role)->whereKey($news->id)->exists();
        abort_unless($visible, 404);

        $engagement = NewsPostEngagement::query()->firstOrNew([
            'news_post_id' => $news->id,
            'user_id' => $user->id,
        ]);

        // Keeps the FIRST read time. Overwriting it on every open would turn "when did this reach
        // people" into "when was it last opened", which answers a different question.
        $engagement->read_at ??= now();
        $engagement->save();

        return response()->json(['data' => ['is_read' => true]]);
    }

    /**
     * Sets or clears the caller's reaction. Sending the reaction already stored clears it, so the
     * same tap toggles — which is what a reaction control is expected to do.
     */
    public function react(Request $request, NewsPost $news): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'reaction' => ['required', 'string', 'in:'.implode(',', NewsPost::REACTIONS)],
        ]);

        $visible = NewsPost::query()->visibleTo($user->role)->whereKey($news->id)->exists();
        abort_unless($visible, 404);

        $engagement = NewsPostEngagement::query()->firstOrNew([
            'news_post_id' => $news->id,
            'user_id' => $user->id,
        ]);

        $engagement->reaction = $engagement->reaction === $validated['reaction']
            ? null
            : $validated['reaction'];
        $engagement->read_at ??= now();
        $engagement->save();

        $counts = NewsPostEngagement::query()
            ->where('news_post_id', $news->id)
            ->whereNotNull('reaction')
            ->groupBy('reaction')
            ->select('reaction', DB::raw('count(*) as total'))
            ->pluck('total', 'reaction');

        return response()->json([
            'data' => [
                'my_reaction' => $engagement->reaction,
                'reaction_counts' => $counts,
            ],
        ]);
    }
}
