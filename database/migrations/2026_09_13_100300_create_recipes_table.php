<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recipe (bill of materials) is append-only/versioned like ProductPriceVersion — R10's
 * reasoning applies here just as much: a correction to how much milk a latte takes is a new
 * version, never an edit of the old row, because the old row may already have been the recipe
 * snapshot on a past production line (see production_lines.recipe_id/recipe_cost_minor).
 *
 * "The current recipe" for a product is resolved the same way Product::currentPriceVersion()
 * resolves the current price: highest effective_from <= now, is_active = true, tie-broken by
 * id desc — see RecipeService::currentFor().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(true);
            $table->dateTime('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Refreshed whenever a purchase order receives stock for a raw material this recipe
            // uses (RecipeService's cost refresh) — a cache of the last known ingredient cost,
            // never the source of truth. Left null until at least one PURCHASE_IN exists for
            // every ingredient this recipe needs.
            $table->bigInteger('computed_cost_minor')->nullable();

            $table->timestamps();

            $table->unique(['product_id', 'version']);
            $table->index(['product_id', 'is_active', 'effective_from']);
        });

        Schema::create('recipe_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignId('raw_material_id')->constrained('raw_materials')->restrictOnDelete();

            // In the raw material's base unit, consumed per 1 finished unit of the recipe's
            // product — whole integer, same discipline as every other raw-material quantity.
            $table->unsignedInteger('qty_per_unit');

            $table->timestamps();

            $table->unique(['recipe_id', 'raw_material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_lines');
        Schema::dropIfExists('recipes');
    }
};
