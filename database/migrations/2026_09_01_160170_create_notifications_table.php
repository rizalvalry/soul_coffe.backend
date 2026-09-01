<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom app notifications (§8/§12) — NOT Laravel's built-in
     * Illuminate\Notifications\DatabaseNotification table. Columns are
     * user_id/event_id/type/payload_json, not notifiable_type/notifiable_id, so
     * the two are not interchangeable. See App\Models\AppNotification.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // The dedupe key the client uses across WebSocket and push (E15). NOT globally
            // unique: one event fans out to several recipients, and they must all carry the
            // SAME event_id or the client cannot recognise the socket and push copies of one
            // event as the same thing. Uniqueness is therefore per (user, event).
            $table->uuid('event_id');
            $table->string('type'); // e.g. RefillRequestApproved
            $table->json('payload_json');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'read_at']);
            // One notification per recipient per event: makes a re-publish of the same event
            // idempotent instead of duplicating a user's inbox.
            $table->unique(['user_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
