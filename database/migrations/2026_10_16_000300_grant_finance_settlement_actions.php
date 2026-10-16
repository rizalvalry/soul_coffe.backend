<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_permission_preupgrade', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('role_permission_id')->nullable();
            $table->text('abilities')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $prior = DB::table('role_permissions')->where('role', 'FINANCE')->where('module', 'settlements')->first();
        DB::table('settlement_permission_preupgrade')->insert([
            'id' => 1,
            'role_permission_id' => $prior?->id,
            'abilities' => $prior?->abilities,
            'updated_by' => $prior?->updated_by,
            'updated_at' => $prior?->updated_at,
        ]);

        $this->upgradeExistingGrant();
    }

    public function upgradeExistingGrant(): void
    {
        $grant = DB::table('role_permissions')->where('role', 'FINANCE')->where('module', 'settlements')->first();
        if (! $grant) {
            return;
        }

        $abilities = json_decode($grant->abilities, true, flags: JSON_THROW_ON_ERROR);
        $abilities = array_values(array_unique([...$abilities, 'view', 'create', 'edit']));
        DB::table('role_permissions')->where('id', $grant->id)->update([
            'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $prior = DB::table('settlement_permission_preupgrade')->where('id', 1)->first();
        if ($prior?->role_permission_id !== null) {
            DB::table('role_permissions')->where('id', $prior->role_permission_id)->update([
                'abilities' => $prior->abilities,
                'updated_by' => $prior->updated_by,
                'updated_at' => $prior->updated_at,
            ]);
        }
        Schema::drop('settlement_permission_preupgrade');
    }
};
