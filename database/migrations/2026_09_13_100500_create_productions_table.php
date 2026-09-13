<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit anchor for one brew event: a barista brewing one or more products at once, each
 * against the recipe version active at that moment (or no recipe at all, for a product with no
 * BOM yet — see ProductionService). `production_lines.recipe_cost_minor` snapshots the recipe's
 * computed cost as of that brew, so a later recipe cost refresh never rewrites what a past brew
 * is reported to have cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('kitchen_id')->constrained('central_kitchens')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('produced_at');
            $table->timestamps();

            $table->index(['kitchen_id', 'produced_at']);
        });

        Schema::create('production_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_id')->constrained('productions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qty_brewed');

            // Nullable: a product with no active recipe yet still brews (raw-material-blind),
            // matching today's behaviour exactly for a phased rollout — see ProductionService.
            $table->foreignId('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->bigInteger('recipe_cost_minor')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_lines');
        Schema::dropIfExists('productions');
    }
};
