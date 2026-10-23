<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reconciliation_results', function (Blueprint $table): void {
            $table->id();
            $table->date('checked_on')->unique();
            $table->timestamp('checked_at');
            $table->string('status', 16);
            $table->unsignedInteger('finding_count');
            $table->unsignedInteger('pending_cost_rows')->default(0);
            $table->bigInteger('pending_cost_estimated_minor')->default(0);
            $table->json('findings');
            $table->char('findings_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reconciliation_results');
    }
};
