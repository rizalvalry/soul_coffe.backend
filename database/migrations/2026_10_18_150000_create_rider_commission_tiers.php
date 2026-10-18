<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_commission_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });

        Schema::create('rider_commission_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->date('effective_from');
            $table->foreignId('change_id')->constrained('rider_commission_changes')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['rider_id', 'effective_from', 'id']);
        });

        Schema::create('rider_commission_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('rider_commission_versions')->restrictOnDelete();
            $table->unsignedInteger('min_cups');
            $table->unsignedBigInteger('amount_per_cup_minor');
            $table->unique(['version_id', 'min_cups']);
        });

        Schema::create('rider_daily_commission_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->date('operating_date');
            $table->unsignedInteger('cups_sold');
            $table->foreignId('version_id')->nullable()->constrained('rider_commission_versions')->restrictOnDelete();
            $table->unsignedInteger('tier_min_cups')->nullable();
            $table->unsignedBigInteger('amount_per_cup_minor');
            $table->unsignedBigInteger('commission_minor');
            $table->timestamp('locked_at');
            $table->unique(['rider_id', 'operating_date']);
        });

        Schema::create('rider_commission_permission_preupgrade', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('prior_permission_id')->nullable();
            $table->unsignedBigInteger('granted_permission_id')->nullable();
            $table->text('abilities')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        $prior = DB::table('role_permissions')->where('role', 'FINANCE')->where('module', 'rider_commissions')->first();
        DB::table('rider_commission_permission_preupgrade')->insert([
            'id' => 1, 'prior_permission_id' => $prior?->id,
            'granted_permission_id' => null, 'abilities' => $prior?->abilities,
            'updated_by' => $prior?->updated_by, 'updated_at' => $prior?->updated_at,
        ]);
        $this->upgradeExistingGrant();
    }

    public function upgradeExistingGrant(): void
    {
        // A fresh schema has no users yet and gets its defaults from RolePermissionSeeder.
        // An existing database may have had every Finance grant revoked; T-045 restores this one.
        if (! DB::table('users')->exists()) {
            return;
        }
        $grant = DB::table('role_permissions')->where('role', 'FINANCE')->where('module', 'rider_commissions')->first();
        $abilities = $grant ? json_decode($grant->abilities, true, flags: JSON_THROW_ON_ERROR) : [];
        $abilities = array_values(array_unique([...$abilities, 'view', 'create', 'edit']));
        if ($grant) {
            DB::table('role_permissions')->where('id', $grant->id)->update([
                'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        } else {
            $id = DB::table('role_permissions')->insertGetId([
                'role' => 'FINANCE', 'module' => 'rider_commissions',
                'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('rider_commission_permission_preupgrade')->where('id', 1)
                ->update(['granted_permission_id' => $id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rider_commission_permission_preupgrade')) {
            $prior = DB::table('rider_commission_permission_preupgrade')->where('id', 1)->first();
            if ($prior?->prior_permission_id !== null) {
                $grant = DB::table('role_permissions')->where('id', $prior->prior_permission_id)->first();
                $original = json_decode($prior->abilities, true);
                $upgraded = array_values(array_unique([...$original, 'view', 'create', 'edit']));
                // Preserve any Administrator matrix edits made after the upgrade.
                if ($grant && json_decode($grant->abilities, true) === $upgraded) {
                    DB::table('role_permissions')->where('id', $prior->prior_permission_id)->update([
                        'abilities' => $prior->abilities,
                        'updated_by' => $prior->updated_by, 'updated_at' => $prior->updated_at,
                    ]);
                }
            } elseif ($prior?->granted_permission_id !== null) {
                $grant = DB::table('role_permissions')->where('id', $prior->granted_permission_id)->first();
                if ($grant && json_decode($grant->abilities, true) === ['view', 'create', 'edit']) {
                    DB::table('role_permissions')->where('id', $grant->id)->delete();
                }
            }
        }
        Schema::dropIfExists('rider_commission_permission_preupgrade');
        Schema::dropIfExists('rider_daily_commission_locks');
        Schema::dropIfExists('rider_commission_tiers');
        Schema::dropIfExists('rider_commission_versions');
        Schema::dropIfExists('rider_commission_changes');
    }
};
