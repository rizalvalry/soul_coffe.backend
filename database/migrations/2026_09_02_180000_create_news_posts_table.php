<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-app news feed, written by CONTENT_CREATOR in the Filament panel.
 *
 * Two decisions here are worth stating, because they are what separate this from a banner table:
 *
 *  - `audience_roles` makes a post ADDRESSED rather than broadcast. A note about cart hygiene is
 *    for Staff; a kitchen prep tip is for Barista. A feed that shows everyone everything gets
 *    ignored within a week, and an ignored feed is worse than no feed.
 *  - `published_at` / `expires_at` let a creator write ahead and let a post retire itself. Nobody
 *    logs in at 6am to press publish, and nothing rots a feed faster than a promo that ended
 *    three weeks ago still sitting at the top.
 *
 * `body` is deliberately unconstrained long text: the brief was explicitly not to box in the
 * writer's ideas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();

            // The short, loud line above the title — "kata-kata gaul". Separate from the title so
            // the writer can be playful without making the title itself unreadable in a list.
            $table->string('kicker')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('body');

            $table->string('cover_path')->nullable();
            $table->json('tags')->nullable();

            // Drives the card's gradient in the app. Held here rather than in the client so a
            // creator can re-theme a post without shipping a new APK.
            $table->string('accent_color', 9)->nullable();

            // Empty/null = everyone. Stored as a JSON array of Role values.
            $table->json('audience_roles')->nullable();

            $table->string('status')->default('draft'); // draft | published | archived
            $table->boolean('is_highlighted')->default(false); // rides the home slider
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // The feed query is always "visible to this role, live now, ordered" — one index for
            // exactly that, so the home slider never table-scans as the archive grows.
            $table->index(['status', 'published_at']);
            $table->index(['is_highlighted', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_posts');
    }
};
