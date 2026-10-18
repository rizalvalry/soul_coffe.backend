<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_assignments', function (Blueprint $table): void {
            $table->string('source', 20)->default('manual')->after('kitchen_id');
            $table->timestamp('superseded_at')->nullable()->after('source');
            $table->foreignId('location_id')->nullable()->change();
        });

        $this->backfill();
        if (Schema::hasTable('staff_assignment_provenance_rollback')) {
            DB::table('staff_assignment_provenance_rollback')->orderBy('assignment_id')
                ->chunk(200, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('staff_assignments')->where('id', $row->assignment_id)->update([
                            'source' => $row->source,
                            'superseded_at' => $row->superseded_at,
                        ]);
                    }
                });
            Schema::drop('staff_assignment_provenance_rollback');
        }

        Schema::table('staff_assignments', function (Blueprint $table): void {
            $table->index(['user_id', 'operating_date', 'source', 'superseded_at'], 'staff_assignment_active_user');
            $table->index(['cart_id', 'operating_date', 'source', 'superseded_at'], 'staff_assignment_active_cart');
            $table->dropUnique('staff_assignments_user_id_operating_date_unique');
            $table->dropUnique('staff_assignments_cart_id_operating_date_unique');
        });
    }

    public function backfill(): void
    {
        // Old Add Stock transactions recorded the assignment ID in the audit log.
        // Manual and carry-forward rows cannot be distinguished reliably, so keep
        // those conservative while preserving already-stocked Riders' access.
        DB::table('staff_assignments')->update(['source' => 'manual']);
        DB::table('staff_assignments')->whereExists(function ($query): void {
            $query->selectRaw('1')->from('audit_log')
                ->whereColumn('audit_log.subject_id', 'staff_assignments.id')
                ->where('audit_log.subject_type', 'App\\Services\\CentralStockService')
                ->where('audit_log.action', 'central_stock.hand_to_cart');
        })->update(['source' => 'hand_to_cart']);
    }

    public function down(): void
    {
        $this->assertRollbackSafe();

        // Preserve provenance for a later migrate after rollback. Superseded rows and
        // handover rows stay in the main table throughout.
        if (! Schema::hasTable('staff_assignment_provenance_rollback')) {
            Schema::create('staff_assignment_provenance_rollback', function (Blueprint $table): void {
                $table->unsignedBigInteger('assignment_id')->primary();
                $table->string('source', 20);
                $table->timestamp('superseded_at')->nullable();
            });
        }
        DB::table('staff_assignments')->orderBy('id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('staff_assignment_provenance_rollback')->updateOrInsert(
                    ['assignment_id' => $row->id],
                    ['source' => $row->source, 'superseded_at' => $row->superseded_at],
                );
            }
        });

        Schema::table('staff_assignments', function (Blueprint $table): void {
            $table->unique(['user_id', 'operating_date']);
            $table->unique(['cart_id', 'operating_date']);
            $table->dropIndex('staff_assignment_active_user');
            $table->dropIndex('staff_assignment_active_cart');
            $table->dropColumn(['source', 'superseded_at']);
            $table->foreignId('location_id')->nullable(false)->change();
        });
    }

    public function assertRollbackSafe(): void
    {
        $duplicateUser = DB::table('staff_assignments')
            ->select('user_id', 'operating_date')
            ->groupBy('user_id', 'operating_date')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $duplicateCart = DB::table('staff_assignments')
            ->select('cart_id', 'operating_date')
            ->groupBy('cart_id', 'operating_date')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateUser || $duplicateCart || DB::table('staff_assignments')->whereNull('location_id')->exists()) {
            throw new RuntimeException(
                'T-040 rollback needs an audit-preserving plan for duplicate assignments or null locations.'
            );
        }
    }
};
