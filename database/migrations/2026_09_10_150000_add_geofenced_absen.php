<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absen has to happen AT the kitchen — with a way out for the days it cannot.
 *
 * THE RULE
 * --------
 * A Dapur Pusat gets a map pin and a radius (10 m by default). Clocking in from further away than
 * that is refused, because an attendance record that can be created from a bed proves nothing
 * about attendance.
 *
 * THE WAY OUT, AND WHY IT IS PART OF THE FEATURE RATHER THAN A LOOPHOLE
 * ---------------------------------------------------------------------
 * Real days do not fit the rule: a wedding, a car free day, an event running for a week, a mess
 * far from the office, a cart trading in Blok M rather than near the kitchen. Every one of those
 * is legitimate work, and a rule that refuses them would simply be worked around — someone would
 * clock in for someone else. So Administrator and Finance can grant an exemption per gerobak code,
 * for a date range, with a reason:
 *
 *   selling_location — clock in at the cart's own selling point instead of the kitchen;
 *   anywhere         — no geofence at all, for the cases neither point describes.
 *
 * WHY NOTHING BREAKS ON THE DAY THIS SHIPS
 * ----------------------------------------
 * A kitchen with no pin has no geofence, and absen behaves exactly as it did before. The rule
 * switches on for a kitchen the moment an Administrator tags it on the map, one kitchen at a
 * time, which is also how it can be rolled back: clear the pin.
 *
 * The coordinates of each clock-in are stored on the attendance row itself. That is the point of
 * the exercise — the record now carries its own evidence rather than asserting presence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_kitchens', function (Blueprint $table): void {
            // Same shape as `locations` so both can be tagged with the same map picker.
            $table->decimal('lat', 10, 7)->nullable()->after('address');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            // Metres. Default 10 as requested; per kitchen, because a yard and a shophouse are
            // not the same size.
            $table->unsignedSmallInteger('geofence_m')->default(10)->after('lng');
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->decimal('gps_lat', 10, 7)->nullable()->after('clocked_in_at');
            $table->decimal('gps_lng', 10, 7)->nullable()->after('gps_lat');
            // How far the person actually was from whatever point was checked. Kept even when
            // the check passed: "3 m" and "9 m" are different facts on a 10 m rule.
            $table->unsignedInteger('distance_m')->nullable()->after('gps_lng');
            // Which rule let this clock-in through: kitchen | selling_location | exempt |
            // untagged. Without it, a row that passed because the kitchen had no pin would be
            // indistinguishable from one that passed the geofence.
            $table->string('geofence_basis', 24)->nullable()->after('distance_m');
        });

        Schema::create('attendance_exemptions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();

            // 'selling_location' | 'anywhere' — see App\Enums\AbsenExemptionMode.
            $table->string('mode', 24);

            $table->date('effective_from');
            // Open-ended is allowed: a mess far from the kitchen is not a one-week problem.
            $table->date('effective_until')->nullable();

            // Required in the form. An exemption with no stated reason is the kind of record that
            // nobody can review later.
            $table->text('reason');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The only lookup: "is this cart exempt today".
            $table->index(['cart_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_exemptions');

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn(['gps_lat', 'gps_lng', 'distance_m', 'geofence_basis']);
        });

        Schema::table('central_kitchens', function (Blueprint $table): void {
            $table->dropColumn(['lat', 'lng', 'geofence_m']);
        });
    }
};
