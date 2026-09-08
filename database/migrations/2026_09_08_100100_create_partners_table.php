<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People whose attendance is tracked on the monthly absensi sheet.
 *
 * Deliberately NOT a foreign key to `users`: the sheet this reproduces lists partners with no
 * NIK and no login at all (rows 24–27 of the reference screenshot), and a rider who never opens
 * the app still gets paid by how many days they showed up. Where a partner does also have an
 * account, `user_id` links them; where they don't, the row stands on its own.
 *
 * Also distinct from `attendances`, which is the self-service clock-in a Barista/Staff performs
 * from the phone — a timestamp, not an HR code. Merging the two would force every partner to own
 * a phone and every clock-in to become a payroll fact, and neither is true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table): void {
            $table->id();
            $table->string('nik', 32)->nullable()->unique();
            $table->string('name');
            $table->string('role')->default('RIDER');
            $table->string('size', 8)->nullable(); // uniform size, as on the sheet: S / M / L / XL / XXL
            // "Jatah Klibur" — paid days off per month; the sheet shows 4 for everyone, so that is
            // the default, but it is a per-partner field so a different arrangement needs no code.
            $table->unsignedTinyInteger('monthly_libur_quota')->default(4);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
