<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ManagementStatusMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.management_status_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('management_status_test');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('role');
        });
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_role')->nullable();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_legacy_role_is_snapshotted_audited_and_restored_by_up_down_up(): void
    {
        DB::table('users')->insert([['id' => 1, 'role' => 'MANAGEMENT'], ['id' => 2, 'role' => 'FINANCE']]);
        $migration = require database_path('migrations/2026_10_22_000064_add_management_status_to_users.php');

        $migration->up();
        $this->assertDatabaseHas('users', ['id' => 1, 'role' => 'FINANCE', 'is_management' => true]);
        $this->assertDatabaseHas('users', ['id' => 2, 'role' => 'FINANCE', 'is_management' => false]);
        $this->assertDatabaseHas('management_status_snapshots', ['user_id' => 1, 'previous_role' => 'MANAGEMENT']);
        $this->assertDatabaseHas('audit_log', ['subject_id' => 1, 'action' => 'management.status_migrated']);

        DB::table('users')->where('id', 2)->update(['is_management' => true]);
        try {
            $migration->down();
            $this->fail('Rollback discarded a new Management status.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback would discard', $exception->getMessage());
        }
        DB::table('users')->where('id', 2)->update(['is_management' => false]);

        DB::table('users')->where('id', 1)->update(['role' => 'BARISTA']);
        try {
            $migration->down();
            $this->fail('Rollback overwrote a later account edit.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback would overwrite', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('management_status_snapshots'));

        DB::table('users')->where('id', 1)->update(['role' => 'FINANCE']);
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => 1, 'role' => 'MANAGEMENT']);
        $this->assertFalse(Schema::hasColumn('users', 'is_management'));
        $this->assertDatabaseHas('audit_log', ['subject_id' => 1, 'action' => 'management.status_migration_reversed']);

        $migration->up();
        $this->assertDatabaseHas('users', ['id' => 1, 'role' => 'FINANCE', 'is_management' => true]);
    }
}
