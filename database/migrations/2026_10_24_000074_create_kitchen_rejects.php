<?php

use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MARKER = '2026-10-24 00:01:14';

    public function up(): void
    {
        Schema::create('kitchen_rejects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kitchen_id')->constrained('central_kitchens')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('photo_media_id')->constrained('media')->restrictOnDelete();
            $table->date('operating_date');
            $table->string('reason_code', 20);
            $table->string('note', 500)->nullable();
            $table->string('status', 24)->default('RECORDED');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('correction_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['kitchen_id', 'operating_date']);
            $table->index('operating_date');
            $table->index(['status', 'operating_date']);
        });
        Schema::create('kitchen_reject_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kitchen_reject_id')->constrained('kitchen_rejects')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->unique(['kitchen_reject_id', 'product_id']);
        });
        Schema::create('kitchen_reject_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kitchen_id')->constrained('central_kitchens')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('daily_limit')->default(5);
            $table->timestamps();
            $table->unique(['kitchen_id', 'product_id']);
        });

        foreach (['BARISTA' => ['view', 'create'], 'FINANCE' => ['view', 'create', 'edit'],
            'MANAGEMENT' => ['view'], 'ADMINISTRATOR' => ['view']] as $role => $abilities) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => $role, 'module' => 'kitchen_reject',
                'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
                'created_at' => self::MARKER, 'updated_at' => self::MARKER,
            ]);
        }
        PermissionMatrix::forget();
    }

    public function down(): void
    {
        foreach (['BARISTA' => ['view', 'create'], 'FINANCE' => ['view', 'create', 'edit'],
            'MANAGEMENT' => ['view'], 'ADMINISTRATOR' => ['view']] as $role => $abilities) {
            DB::table('role_permissions')->where('module', 'kitchen_reject')->where('role', $role)
                ->where('abilities', json_encode($abilities, JSON_THROW_ON_ERROR))
                ->where('created_at', self::MARKER)->where('updated_at', self::MARKER)
                ->whereNull('updated_by')->delete();
        }
        Schema::dropIfExists('kitchen_reject_limits');
        Schema::dropIfExists('kitchen_reject_lines');
        Schema::dropIfExists('kitchen_rejects');
        PermissionMatrix::forget();
    }
};
