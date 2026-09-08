<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One cell of the monthly absensi sheet: this partner, this date, this code.
 *
 * A row exists only for days that were actually filled in. A blank cell on the sheet is the
 * absence of a row, not a fifth code — so "not yet recorded" and "recorded as something" can
 * never be confused, and the summary columns count only what a human deliberately entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_attendance_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('code', 1); // App\Enums\PartnerAttendanceCode
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The sheet has exactly one cell per partner per day.
            $table->unique(['partner_id', 'entry_date']);
            $table->index('entry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_attendance_entries');
    }
};
