<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who opened a post, and how they reacted to it.
 *
 * This is the row that turns the feed from decoration into a measurable internal channel, and it
 * is the part competitors in this segment generally do not have: a creator can see that 31 of 40
 * staff opened the shift note and that four of them tapped "bingung", which is a signal to
 * rewrite it — not a guess. Without it, nobody can tell whether anything published was ever read.
 *
 * One row per (post, user): opening a post twice is not two reads, and changing a reaction
 * replaces it rather than stacking. `read_at` is nullable because a reaction can arrive from the
 * slider without the article being opened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_post_engagements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_post_id')->constrained('news_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('read_at')->nullable();
            // api | mantap | semangat | bingung — a small fixed set, validated in the request.
            $table->string('reaction', 16)->nullable();
            $table->timestamps();

            $table->unique(['news_post_id', 'user_id']);
            $table->index(['news_post_id', 'reaction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_post_engagements');
    }
};
