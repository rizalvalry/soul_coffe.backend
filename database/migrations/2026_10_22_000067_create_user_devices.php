<?php

use App\Enums\PanelModule;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 255);
            $table->string('device_name', 255);
            $table->string('model', 255)->nullable();
            $table->string('os_version', 100)->nullable();
            $table->string('app_version', 100)->nullable();
            $table->string('status', 20);
            // NULL permits many requests; 1 permits exactly one ACTIVE device per account.
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_id']);
            $table->unique(['user_id', 'active_slot']);
            $table->index('status');
        });

        Schema::create('device_login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_device_id')->nullable()->constrained('user_devices')->nullOnDelete();
            $table->string('device_id', 255)->nullable();
            $table->string('device_name', 255)->nullable();
            $table->string('model', 255)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->boolean('succeeded');
            $table->string('reason', 100);
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('user_device_id')->nullable()->constrained('user_devices')->nullOnDelete();
        });
        Schema::table('device_push_tokens', function (Blueprint $table): void {
            $table->foreignId('user_device_id')->nullable()->constrained('user_devices')->nullOnDelete();
        });

        foreach ([Role::ADMINISTRATOR, Role::FINANCE, Role::MANAGEMENT] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role->value,
                'module' => PanelModule::DEVICES->value,
                'abilities' => json_encode(['view', 'create', 'edit', 'delete'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('device_push_tokens', 'user_device_id')) {
            Schema::table('device_push_tokens', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('user_device_id');
            });
        }
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_device_id');
        });
        Schema::dropIfExists('device_login_attempts');
        Schema::dropIfExists('user_devices');
        // Preserve role permission edits made after deployment, as in T-057.
    }
};
