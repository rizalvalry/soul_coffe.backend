<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_management')->default(false);
        });
        Schema::create('management_status_snapshots', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('previous_role');
            $table->boolean('previous_is_management');
        });

        DB::transaction(function (): void {
            foreach (DB::table('users')->where('role', 'MANAGEMENT')->orderBy('id')->get() as $user) {
                DB::table('management_status_snapshots')->insert([
                    'user_id' => $user->id,
                    'previous_role' => $user->role,
                    'previous_is_management' => (bool) $user->is_management,
                ]);
                DB::table('users')->where('id', $user->id)->update([
                    'role' => 'FINANCE', 'is_management' => true,
                ]);
                DB::table('audit_log')->insert([
                    'actor_role' => 'SYSTEM', 'action' => 'management.status_migrated',
                    'subject_type' => User::class, 'subject_id' => $user->id,
                    'before_json' => json_encode(['role' => $user->role, 'is_management' => false]),
                    'after_json' => json_encode(['role' => 'FINANCE', 'is_management' => true]),
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $migratedIds = DB::table('management_status_snapshots')->pluck('user_id')->all();
            if (DB::table('users')->where('is_management', true)->whereNotIn('id', $migratedIds)->exists()) {
                throw new RuntimeException('New Management statuses exist; rollback would discard them.');
            }
            foreach (DB::table('management_status_snapshots')->orderBy('user_id')->get() as $snapshot) {
                $user = DB::table('users')->where('id', $snapshot->user_id)->first();
                if ($user === null || $user->role !== 'FINANCE' || ! $user->is_management) {
                    throw new RuntimeException('Management account changed after migration; rollback would overwrite it.');
                }
                DB::table('users')->where('id', $snapshot->user_id)->update([
                    'role' => $snapshot->previous_role,
                    'is_management' => $snapshot->previous_is_management,
                ]);
                DB::table('audit_log')->insert([
                    'actor_role' => 'SYSTEM', 'action' => 'management.status_migration_reversed',
                    'subject_type' => User::class, 'subject_id' => $snapshot->user_id,
                    'before_json' => json_encode(['role' => 'FINANCE', 'is_management' => true]),
                    'after_json' => json_encode(['role' => $snapshot->previous_role,
                        'is_management' => (bool) $snapshot->previous_is_management]),
                    'created_at' => now(),
                ]);
            }
        });

        Schema::drop('management_status_snapshots');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_management');
        });
    }
};
