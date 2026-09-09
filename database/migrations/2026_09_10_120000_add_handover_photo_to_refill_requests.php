<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The photo a rider takes when the cups change hands.
 *
 * WHY THIS REPLACES THE SIGNATURE AS THE REQUIRED ARTEFACT
 * --------------------------------------------------------
 * A finger-drawn squiggle on a phone proves that somebody drew a squiggle. A photograph of the
 * handover shows the cups, the cart, and the person receiving them — it is the evidence anyone
 * would actually want when a delivery is disputed. So from 2026-09-10 the photo is required and
 * the signature is optional, which is also one less thing to do while standing in the street
 * holding a crate.
 *
 * NULLABLE IN THE SCHEMA, REQUIRED AT THE BOUNDARY
 * ------------------------------------------------
 * Every delivery recorded before today has no photo, and a NOT NULL column would either lose
 * that history or invent a value for it. The requirement therefore lives in
 * DeliverRefillRequestRequest, where it can describe today's rule without rewriting the past.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refill_requests', function (Blueprint $table): void {
            $table->foreignId('handover_photo_id')
                ->nullable()
                ->after('signature_method')
                // Same as the evidence photo: media rows are evidence, so a delivery may not
                // delete the picture that proves it happened.
                ->constrained('media')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('refill_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('handover_photo_id');
        });
    }
};
