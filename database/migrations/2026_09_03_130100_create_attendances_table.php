<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service clock-in ("absen") for Barista and Staff — one row per person per operating day.
 *
 * Deliberately NOT folded into `staff_assignments`: an assignment is a roster decision made for
 * someone ("you are on cart 0018 today"), while this records something the person did themselves
 * at a moment in time. Storing both in one row would make it impossible to tell a staff member
 * who was rostered and never showed up from one who was rostered and did.
 *
 * `clocked_in_at` is server time (R16): the client's clock is not trusted for anything that
 * amounts to a record of when somebody started work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table): void {
            $table->id();
            $table->date('operating_date');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // The role AS AT clock-in, not a live join to users.role: someone whose role changes
            // later must not silently rewrite what they were when they clocked in.
            $table->string('role');

            $table->timestamp('clocked_in_at');
            $table->timestamps();

            // One clock-in per person per day — a second tap is a no-op replay, not a new row.
            $table->unique(['user_id', 'operating_date'], 'attendance_user_date_unique');
            $table->index(['operating_date', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
