<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('t072_retired_products')) {
            Schema::create('t072_retired_products', function (Blueprint $table): void {
                $table->unsignedBigInteger('product_id')->primary();
                $table->timestamp('previous_updated_at')->nullable();
                $table->timestamp('retired_updated_at');
            });
        }

        // MySQL commits each ALTER TABLE. Guards let an interrupted migration resume safely.
        foreach (['refill_requests', 'rider_closings', 'staff_assignments'] as $table) {
            if (! Schema::hasColumn($table, 'note')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->string('note', 500)->nullable();
                });
            }
        }

        $products = DB::table('products')->where('is_active', true)
            ->where(fn ($query) => $query->where('is_sellable', false)->orWhereRaw('UPPER(name) = ?', ['ES BATU']))
            ->get(['id', 'updated_at']);
        foreach ($products as $product) {
            $retiredAt = DB::table('t072_retired_products')->where('product_id', $product->id)
                ->value('retired_updated_at') ?? now();
            DB::table('t072_retired_products')->insertOrIgnore([
                'product_id' => $product->id,
                'previous_updated_at' => $product->updated_at,
                'retired_updated_at' => $retiredAt,
            ]);
            DB::table('products')->where('id', $product->id)->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => $retiredAt]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('t072_retired_products')) {
            foreach (DB::table('t072_retired_products')->get() as $product) {
                DB::table('products')->where('id', $product->product_id)
                    ->where('is_active', false)->where('updated_at', $product->retired_updated_at)
                    ->update(['is_active' => true, 'updated_at' => $product->previous_updated_at]);
            }
            Schema::drop('t072_retired_products');
        }
        foreach (['refill_requests', 'rider_closings', 'staff_assignments'] as $table) {
            if (Schema::hasColumn($table, 'note')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('note');
                });
            }
        }
    }
};
