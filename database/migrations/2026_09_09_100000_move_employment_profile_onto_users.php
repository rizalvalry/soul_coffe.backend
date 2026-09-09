<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folds the absensi roster back into `users`, and drops the parallel `partners` roster.
 *
 * WHY: yesterday's module invented a second roster (`partners`) whose rows described the same
 * people already in `users`, so an administrator had to type Dimas and Mufit twice before the
 * report would show them. Worse, "partner" is a word this business needs for something else
 * entirely — mitra, investor, franchisee, an outside company joining Soul Coffee — and spending
 * it on "employee with a NIK" would have made that later feature impossible to name.
 *
 * The employment facts the sheet needs (NIK, uniform size, monthly paid-days-off quota) are
 * properties of a person the company employs, so they belong on the row that already represents
 * that person.
 *
 * SAFETY: `partners` and `partner_attendance_entries` are dropped rather than migrated because
 * both are empty in production — verified immediately before this ran (partners=0, entries=0).
 * The guard below re-checks at run time and refuses to drop a table that has rows, so this can
 * never silently discard somebody's work on a different environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Nullable: the sheet this reproduces carries people with no NIK issued yet, and
            // refusing to record them would push those names back into a spreadsheet.
            $table->string('nik', 32)->nullable()->unique()->after('phone_e164');
            $table->string('uniform_size', 8)->nullable()->after('nik'); // S / M / L / XL / XXL
            // "Jatah klibur" — paid days off per month. The reference sheet shows 4 for everyone,
            // so that is the default, but it is per-person so a different arrangement needs no code.
            $table->unsignedTinyInteger('monthly_libur_quota')->default(4)->after('uniform_size');
        });

        // The manual half of the monthly sheet. Presence (M) is derived from `attendances` — the
        // clock-in the person made themselves — so a row here exists only where a human decided
        // something the app cannot know: a day off, sickness, a late start, or presence for a
        // role that has no clock-in at all (RIDER).
        Schema::create('attendance_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('code', 1); // App\Enums\AttendanceCode
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One mark per person per day — the sheet has exactly one cell.
            $table->unique(['user_id', 'entry_date']);
            $table->index('entry_date');
        });

        // The access matrix stored module names; two of them no longer exist. Rewritten rather
        // than deleted, so FINANCE keeps the absensi access it was granted yesterday instead of
        // silently losing the menu.
        DB::table('role_permissions')->where('module', 'partner_attendance')->update(['module' => 'attendance']);
        DB::table('role_permissions')->where('module', 'partners')->delete();

        $this->dropIfEmpty('partner_attendance_entries');
        $this->dropIfEmpty('partners');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_marks');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['nik']);
            $table->dropColumn(['nik', 'uniform_size', 'monthly_libur_quota']);
        });

        DB::table('role_permissions')->where('module', 'attendance')->update(['module' => 'partner_attendance']);
    }

    /**
     * Drops a table only while it is still empty.
     *
     * A migration that deletes rows nobody reviewed is a migration that eventually deletes the
     * wrong rows. If this ever finds data, it stops and says so rather than guessing.
     */
    private function dropIfEmpty(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $rows = DB::table($table)->count();

        if ($rows > 0) {
            throw new RuntimeException(
                "Refusing to drop `{$table}`: it holds {$rows} row(s). Migrate them onto ".
                '`attendance_marks` (keyed by user_id) by hand, then re-run this migration.'
            );
        }

        Schema::drop($table);
    }
};
