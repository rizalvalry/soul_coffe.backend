<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The access matrix: which panel menus each role may reach, and how far.
 *
 * One row per (role, module). `abilities` is the granted subset of view/create/edit/delete. An
 * absent row means "no access" — so an empty table is the safest possible state, and
 * ADMINISTRATOR is never looked up here at all (see App\Services\Access\PermissionMatrix): the
 * role that edits this table must not be able to lock itself out through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('role');
            $table->string('module');
            $table->json('abilities');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['role', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
