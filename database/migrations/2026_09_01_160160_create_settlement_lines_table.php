<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->unsignedInteger('qty_issued'); // allocation + all refills received, projected from ledger
            $table->unsignedInteger('qty_sold');
            $table->unsignedInteger('qty_remaining');
            $table->unsignedInteger('qty_wasted')->default(0);
            // Signed: qty_issued - (qty_sold + qty_remaining + qty_wasted). Non-zero
            // requires settlements.variance_reason (Flow C step 2).
            $table->integer('variance_qty')->default(0);

            $table->timestamps();

            $table->unique(['settlement_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_lines');
    }
};
