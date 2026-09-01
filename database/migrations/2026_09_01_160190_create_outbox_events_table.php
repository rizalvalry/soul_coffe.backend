<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transactional outbox (§12): written in the same transaction as the state
     * change, published by a worker, so a Reverb restart never silently drops a
     * realtime notification.
     */
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('name'); // e.g. RefillRequestApproved
            $table->json('payload_json');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
