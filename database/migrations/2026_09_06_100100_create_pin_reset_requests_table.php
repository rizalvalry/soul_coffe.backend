<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Lupa PIN" requests raised from the login screen, resolved by an Administrator in the panel.
 *
 * Once an account has a login PIN its password no longer opens the app (AuthController::login),
 * so a forgotten PIN would otherwise be a dead end. The recovery is deliberately NOT self-service:
 * the requester proves what they can (the account password, recorded here as a verified/unverified
 * flag, never the password itself), and an Administrator — who can recognise the person — issues
 * a brand-new password and clears the PIN. That keeps a stolen phone plus a guessed password from
 * being enough to take over the account unattended.
 *
 * Only ONE row per user may be PENDING at a time; a repeat submission refreshes that row. This is
 * enforced in the controller rather than by a partial unique index because MySQL 8 has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pin_reset_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('phone_e164', 32);
            $table->string('email');
            // Whether the password typed with the request matched the account at that moment.
            // Shown to the administrator as a trust signal; never blocks the request, so someone
            // who has forgotten both credentials can still reach a human.
            $table->boolean('password_verified')->default(false);
            $table->string('status', 16)->default('PENDING'); // PENDING | RESOLVED | REJECTED
            $table->string('requested_ip', 45)->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_reset_requests');
    }
};
