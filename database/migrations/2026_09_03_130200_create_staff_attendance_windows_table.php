<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gate that lets Staff clock in at all on a given day.
 *
 * Staff cannot absen until a Barista has (a) clocked in themselves and then (b) pressed "Open
 * Absen" — the real-world order being that the coffee has to be brewed and in the showcase
 * before there is anything for a staff member to go out and sell. One row per operating day
 * opens it for EVERY staff member (a deliberate product decision: global, not per-cart), so the
 * gate is a single fact about the day rather than a per-person permission to maintain.
 *
 * Absence of a row for today IS the closed state — nothing to reset overnight, and no daily job
 * needed to re-lock it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendance_windows', function (Blueprint $table): void {
            $table->id();

            // Unique on its own: one gate per day, opened once, by whoever got there first.
            $table->date('operating_date')->unique();

            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendance_windows');
    }
};
