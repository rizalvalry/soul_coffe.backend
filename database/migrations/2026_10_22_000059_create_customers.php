<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('google_sub')->unique();
            $table->string('email')->nullable();
            $table->boolean('email_verified')->default(false);
            $table->string('name')->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'created_at']);
        });

        foreach (['FINANCE', 'MANAGEMENT', 'ADMINISTRATOR'] as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role,
                'module' => 'customers',
                'abilities' => json_encode(['view', 'create', 'edit', 'delete'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $grants = DB::table('role_permissions')->where('module', 'customers')->get(['role', 'abilities']);
        $expected = ['FINANCE', 'MANAGEMENT', 'ADMINISTRATOR'];
        if (DB::table('customers')->exists() || $grants->count() !== 3
            || $grants->contains(fn ($grant): bool => ! in_array($grant->role, $expected, true)
                || json_decode($grant->abilities, true, 512, JSON_THROW_ON_ERROR)
                    !== ['view', 'create', 'edit', 'delete'])) {
            throw new RuntimeException('Simpan data pelanggan dan perubahan hak akses sebelum rollback T-059.');
        }
        DB::table('role_permissions')->where('module', 'customers')->delete();
        Schema::dropIfExists('customers');
    }
};
