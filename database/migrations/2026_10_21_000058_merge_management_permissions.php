<?php

use App\Services\Access\DefaultPermissions;
use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('management_permission_merge_snapshots')) {
            return;
        }
        Schema::create('management_permission_merge_snapshots', function (Blueprint $table): void {
            $table->string('module')->primary();
            $table->unsignedBigInteger('role_permission_id')->nullable();
            $table->json('prior_abilities')->nullable();
            $table->timestamp('prior_updated_at')->nullable();
            $table->unsignedBigInteger('applied_role_permission_id')->nullable();
            $table->json('applied_abilities');
        });

        DB::transaction(fn () => DefaultPermissions::mergeManagement());
    }

    public function down(): void
    {
        DB::transaction(fn () => DefaultPermissions::restoreManagement());

        Schema::drop('management_permission_merge_snapshots');
        PermissionMatrix::forget();
    }
};
