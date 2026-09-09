<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The product photo the mobile "request cups" screen shows.
 *
 * Until now those tiles were BUNDLED IN THE APK (nine 360×360 images, checked by
 * `npm run apk:verify`), which meant adding a product or changing its photo required a new
 * release and every phone to update. The image belongs to the product record, so the panel owns
 * it and the app fetches whatever is current.
 *
 * Nullable: a product without a photo is normal (a new one, added mid-shift), and the app falls
 * back to a neutral tile rather than a broken image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });
    }
};
