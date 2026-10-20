<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_location_setups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('basis', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['rider_id', 'superseded_at']);
        });

        Schema::table('staff_assignments', function (Blueprint $table): void {
            $table->boolean('location_setup_missing')->default(false);
        });
        Schema::table('rider_closings', function (Blueprint $table): void {
            $table->string('closing_basis', 32)->nullable();
            $table->unsignedInteger('closing_distance_m')->nullable();
            $table->boolean('outside_closing_point')->default(false);
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        // The source assignments stay intact. Re-running this backfill cannot replace a setup.
        DB::table('users')->where('role', 'STAFF')->where('is_active', true)
            ->orderBy('id')->chunkById(200, function ($riders): void {
                foreach ($riders as $rider) {
                    $last = DB::table('staff_assignments')->where('user_id', $rider->id)
                        ->whereNotNull('location_id')->orderByDesc('operating_date')
                        ->orderByDesc('id')->first();
                    if ($last && ! DB::table('rider_location_setups')->where('rider_id', $rider->id)->exists()) {
                        DB::table('rider_location_setups')->insert([
                            'rider_id' => $rider->id,
                            'location_id' => $last->location_id,
                            'basis' => 'kitchen',
                            'changed_by' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Refuse rollback before any schema change when setup history or location evidence exists.
        if (DB::table('rider_location_setups')->exists()
            || DB::table('staff_assignments')->where('location_setup_missing', true)->exists()
            || DB::table('rider_closings')->where(function ($query): void {
                $query->whereNotNull('closing_basis')
                    ->orWhereNotNull('closing_distance_m')
                    ->orWhere('outside_closing_point', true);
            })->exists()) {
            throw new RuntimeException('T-049 setup history or closing location evidence must be preserved before rollback.');
        }

        Schema::table('rider_closings', function (Blueprint $table): void {
            $table->dropColumn(['closing_basis', 'closing_distance_m', 'outside_closing_point']);
        });
        Schema::table('staff_assignments', function (Blueprint $table): void {
            $table->dropColumn('location_setup_missing');
        });
        Schema::dropIfExists('rider_location_setups');
    }
};
