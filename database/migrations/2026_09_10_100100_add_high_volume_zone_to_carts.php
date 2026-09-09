<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a cart as standing somewhere genuinely busy.
 *
 * A single sale above the suspect threshold (config `soul.sale_suspect_qty_threshold`, 15) raises
 * a flag for Administrator and Finance. In a crowded zone that is simply Tuesday, and a flag that
 * cries wolf every hour is a flag nobody reads — so an Administrator can exempt those carts here.
 *
 * This changes NOTHING about whether the sale is accepted. It never did: the threshold only ever
 * decided whether a notification goes out. Exempting a cart silences the notification for it;
 * the sale itself is recorded identically either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->boolean('high_volume_zone')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn('high_volume_zone');
        });
    }
};
