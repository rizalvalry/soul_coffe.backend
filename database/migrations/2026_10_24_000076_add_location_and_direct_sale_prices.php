<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('location_price_versions')) {
            Schema::create('location_price_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')->constrained('products');
                $table->foreignId('location_id')->constrained('locations');
                $table->unsignedBigInteger('sell_price_minor');
                $table->date('effective_from');
                $table->timestamps();
                $table->index(['product_id', 'location_id', 'effective_from'], 'location_price_lookup');
            });
        } elseif (! Schema::hasIndex('location_price_versions', 'location_price_lookup')) {
            // MySQL DDL is not transactional; resume an interrupted first attempt safely.
            Schema::table('location_price_versions', fn (Blueprint $table) => $table->index(
                ['product_id', 'location_id', 'effective_from'], 'location_price_lookup'
            ));
        }

        if (! Schema::hasColumn('direct_sales', 'buyer_name')) {
            Schema::table('direct_sales', fn (Blueprint $table) => $table->string('buyer_name')->nullable());
        }
        if (! Schema::hasColumn('direct_sale_lines', 'price_reason')) {
            Schema::table('direct_sale_lines', fn (Blueprint $table) => $table->string('price_reason', 500)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('direct_sale_lines', fn (Blueprint $table) => $table->dropColumn('price_reason'));
        Schema::table('direct_sales', fn (Blueprint $table) => $table->dropColumn('buyer_name'));
        Schema::dropIfExists('location_price_versions');
    }
};
