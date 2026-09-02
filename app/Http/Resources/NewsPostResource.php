<?php

namespace App\Http\Resources;

use App\Models\NewsPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /news` and `GET /news/{id}` (docs/04).
 *
 * `body` is omitted from list responses. A feed of thirty articles would otherwise ship thirty
 * full article bodies to a phone on a cellular connection to render thirty cards — the list needs
 * the excerpt, and nothing more, until something is opened.
 */
class NewsPostResource extends JsonResource
{
    public function __construct($resource, private readonly bool $withBody = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var NewsPost $post */
        $post = $this->resource;

        // Loaded by the controller as `myEngagement` — a per-record query here would be an N+1
        // across the whole feed.
        $mine = $post->relationLoaded('engagements') ? $post->engagements->first() : null;

        $data = [
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'kicker' => $post->kicker,
            'excerpt' => $post->excerpt,
            'cover_url' => $post->coverUrl(),
            'tags' => $post->tags ?? [],
            'accent_color' => $post->accent_color,
            'is_highlighted' => $post->is_highlighted,
            'published_at' => $post->published_at?->toIso8601String(),
            'author_name' => $post->author?->name,
            'reaction_counts' => $post->reaction_counts ?? [],
            'my_reaction' => $mine?->reaction,
            'is_read' => $mine?->read_at !== null,
        ];

        if ($this->withBody) {
            $data['body'] = $post->body;
        }

        return $data;
    }
}
