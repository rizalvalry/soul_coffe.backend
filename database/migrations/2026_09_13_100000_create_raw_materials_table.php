<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — raw materials & recipes (BOM). A raw material is the ingredient side of stock,
 * tracked in the same `stock_ledger` table as finished products (see the stock_ledger alteration
 * migration) but under its own catalogue here, since a raw material has no price version, no
 * sellability, and is bought in bulk rather than brewed one cup at a time.
 *
 * `unit` stores the smallest purchasable unit (g, ml, pcs, ...) and every quantity against a raw
 * material — recipe lines, purchase order lines, ledger deltas — is a whole integer in that unit.
 * The same whole-integer discipline R9 already applies to money applies here to quantities: no
 * fractional grams, so arithmetic never drifts on rounding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_materials', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('unit', 16);
            $table->unsignedInteger('reorder_point')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_materials');
    }
};
