<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push-notification registrations: one row per physical device the app is installed on.
 *
 * Keyed by the FCM token, not by user, on purpose. A phone is shared far more often than an
 * account is: when a second staff member signs in on the same handset the token must follow the
 * person now holding it, otherwise the previous user's approvals keep ringing in someone else's
 * pocket. Re-registering the same token therefore UPDATES `user_id` rather than inserting a
 * duplicate — see DeviceController.
 *
 * Deliberately separate from `personal_access_tokens`: a Sanctum token is a session and dies on
 * logout, while a push token belongs to the device and outlives every session on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_push_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // FCM registration tokens are ~160 chars today; 512 leaves room for the provider to
            // grow them without a migration.
            $table->string('token', 512);
            $table->string('platform', 16)->default('android');
            $table->string('device_name')->nullable();
            $table->timestamp('last_seen_at');
            // Set when the provider reported the token as unregistered; the row is deleted right
            // after, this only survives if the delete itself failed.
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // MySQL 8 InnoDB caps a unique index at 3072 bytes (768 utf8mb4 chars); 512 fits.
            $table->unique('token', 'device_push_tokens_token_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_push_tokens');
    }
};
