<?php

use App\Services\Access\DefaultPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false);
        });

        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module');
            $table->json('abilities');
            $table->string('reason')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'module']);
        });

        DefaultPermissions::seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_super_admin');
        });
        // Keep role grants: deleting them could erase edits made after this migration.
    }
};
