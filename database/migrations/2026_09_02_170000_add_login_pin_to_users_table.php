<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PIN the user may sign in with, as an alternative to typing their password.
 *
 * Deliberately NOT the existing `pin_hash`. That one is the staff PIN a Rider asks for at the
 * door when a signature cannot be captured (R13/E7) — it is a proof-of-presence control, held by
 * the person being delivered to. Turning it into a login credential would mean every Rider who
 * has ever completed a PIN-fallback delivery could sign in as that staff member. Two purposes,
 * two secrets.
 *
 * `login_pin_failed_at` / `login_pin_failures` back the lockout in AuthController: a 6-digit PIN
 * is only 10^6 wide, so throttling it is not optional the way it is for a password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('login_pin_hash')->nullable()->after('pin_hash');
            $table->unsignedTinyInteger('login_pin_failures')->default(0)->after('login_pin_hash');
            $table->timestamp('login_pin_locked_until')->nullable()->after('login_pin_failures');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['login_pin_hash', 'login_pin_failures', 'login_pin_locked_until']);
        });
    }
};
