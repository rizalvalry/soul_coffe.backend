<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->unsignedBigInteger('expected_cash_minor')->nullable()->after('expected_total_minor');
            $table->unsignedBigInteger('expected_qris_minor')->nullable()->after('expected_cash_minor');
            $table->unsignedBigInteger('expected_transfer_minor')->nullable()->after('expected_qris_minor');
            $table->unsignedBigInteger('expected_cogs_minor')->nullable()->after('expected_transfer_minor');
            $table->boolean('expected_stale')->default(false)->after('expected_cogs_minor');
            $table->bigInteger('stale_delta_minor')->default(0)->after('expected_stale');
        });

        if (Schema::hasTable('settlement_expectation_backups')) {
            DB::table('settlement_expectation_backups')->orderBy('settlement_id')->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('settlements')->where('id', $row->settlement_id)->update([
                        'expected_cash_minor' => $row->expected_cash_minor,
                        'expected_qris_minor' => $row->expected_qris_minor,
                        'expected_transfer_minor' => $row->expected_transfer_minor,
                        'expected_cogs_minor' => $row->expected_cogs_minor,
                        'expected_stale' => $row->expected_stale,
                        'stale_delta_minor' => $row->stale_delta_minor,
                    ]);
                }
            });
            Schema::drop('settlement_expectation_backups');
        } else {
            // Existing rows have no per-method or HPP snapshot. Preserve that unknown state,
            // but align stale deltas with today's live sales before atomic sale updates begin.
            DB::table('settlements')->orderBy('id')->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $live = (int) DB::table('sales')->where('cart_id', $row->cart_id)
                        ->whereDate('operating_date', $row->operating_date)->whereNull('voided_at')
                        ->sum('total_amount_minor');
                    $delta = $live - (int) $row->expected_total_minor;
                    DB::table('settlements')->where('id', $row->id)->update([
                        'expected_stale' => $delta !== 0,
                        'stale_delta_minor' => $delta,
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::create('settlement_expectation_backups', function (Blueprint $table): void {
            $table->unsignedBigInteger('settlement_id')->primary();
            $table->unsignedBigInteger('expected_cash_minor')->nullable();
            $table->unsignedBigInteger('expected_qris_minor')->nullable();
            $table->unsignedBigInteger('expected_transfer_minor')->nullable();
            $table->unsignedBigInteger('expected_cogs_minor')->nullable();
            $table->boolean('expected_stale');
            $table->bigInteger('stale_delta_minor');
        });
        DB::table('settlements')->orderBy('id')->chunk(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('settlement_expectation_backups')->insert([
                    'settlement_id' => $row->id,
                    'expected_cash_minor' => $row->expected_cash_minor,
                    'expected_qris_minor' => $row->expected_qris_minor,
                    'expected_transfer_minor' => $row->expected_transfer_minor,
                    'expected_cogs_minor' => $row->expected_cogs_minor,
                    'expected_stale' => $row->expected_stale,
                    'stale_delta_minor' => $row->stale_delta_minor,
                ]);
            }
        });
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropColumn(['expected_cash_minor', 'expected_qris_minor', 'expected_transfer_minor', 'expected_cogs_minor', 'expected_stale', 'stale_delta_minor']);
        });
    }
};
