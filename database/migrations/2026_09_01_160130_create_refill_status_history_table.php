<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refill_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refill_request_id')->constrained('refill_requests')->cascadeOnDelete();
            $table->string('from_status')->nullable(); // null for the initial SUBMITTED row
            $table->string('to_status');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role'); // snapshot of Role at the time — role can change later
            $table->text('reason')->nullable();
            $table->string('device_id')->nullable();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            // R8: audit row. Append-only history, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['refill_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refill_status_history');
    }
};
