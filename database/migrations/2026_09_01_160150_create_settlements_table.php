<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->date('operating_date');
            $table->foreignId('cart_id')->constrained('carts')->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('SUBMITTED'); // SUBMITTED | RECONCILED | VARIANCE_FLAGGED

            // R9: BIGINT rupiah, scale = 0.
            $table->unsignedBigInteger('cash_minor')->default(0);
            $table->unsignedBigInteger('qris_minor')->default(0);
            $table->unsignedBigInteger('transfer_minor')->default(0);
            $table->unsignedBigInteger('declared_total_minor')->default(0);
            $table->unsignedBigInteger('expected_total_minor')->default(0);
            $table->bigInteger('variance_minor')->default(0); // signed — can be over or under
            $table->text('variance_reason')->nullable();

            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();

            $table->timestamps();

            $table->unique(['cart_id', 'operating_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
