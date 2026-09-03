<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily operational allowance (uang makan/minum) each cart receives, one row per cart per
 * operating day.
 *
 * This table records one plain fact and nothing more: cart X received Rp N on date Y. It carries
 * no opinion about whether that money is a company expense or something the staff must account
 * for at settlement — that reading lives in `soul.allowance_counts_toward_settlement`, so the
 * rule can change later without a data migration and without rewriting what was true last month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_cart_allowances', function (Blueprint $table): void {
            $table->id();
            $table->date('operating_date');
            $table->foreignId('cart_id')->constrained('carts')->restrictOnDelete();

            // Whole rupiah, scale 0 (R9) — no floats anywhere in the money path.
            $table->unsignedBigInteger('amount_minor');

            // Distinguishes the untouched 00:00 default from a figure a barista deliberately
            // changed, which is the difference between "nobody looked at this" and "someone
            // decided it should be different today" when Finance reviews the day.
            $table->boolean('is_edited')->default(false);

            // Null for the scheduled 00:00 writer; set when a barista edits the amount.
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One allowance per cart per day. Without this, a retried 00:00 run or a double
            // form submit would quietly hand the same cart the allowance twice.
            $table->unique(['cart_id', 'operating_date'], 'daily_cart_allowance_unique');
            $table->index('operating_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_cart_allowances');
    }
};
