<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single-row table, not a key/value store: there is exactly one AI provider configuration for
 * the whole panel (NewsArticleGenerator, and later the Dashboard sales-insight summary), and a
 * single row is simpler to reason about than a generic settings table for a shape this narrow.
 *
 * Lets an Administrator manage the Gemini key from the panel (Filament\Pages\ManageAiSettings)
 * instead of editing .env over SSH — the whole reason this exists is that the previous flow
 * needed a deploy for every key/model change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            // Encrypted at rest (see AiSetting's cast) — this table is reachable by anyone with
            // database access, unlike .env which at least stays off the web root.
            $table->text('gemini_api_key')->nullable();
            $table->string('gemini_model')->default('gemini-2.0-flash');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
