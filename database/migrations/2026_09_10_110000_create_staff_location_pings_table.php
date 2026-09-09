<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a staff member's phone was, over time — the raw material behind "Aktivitas Staff" and,
 * later, the area × hour analysis (Pulomas at 09:00 vs Cempaka Mas at 10:00).
 *
 * DESIGN NOTES
 * ------------
 * • This is an append-only trail, not a "current position" column on `users`. A single mutable
 *   column would answer "where is Mufit now" and destroy the only question the analytics
 *   actually needs answered: where was the cart at each hour of the day.
 *
 * • High volume by nature — one row per phone per interval. So: narrow columns, three indexes
 *   chosen for the three real queries (latest per person, one person's day, everyone's day),
 *   and a retention window enforced by `soul:prune-location-pings`. On shared hosting an
 *   unbounded table is not a theoretical problem.
 *
 * • `source` records WHY the row exists. A ping from the background tracker is weaker evidence
 *   than a coordinate captured at the moment of a sale, and a report that mixes them without
 *   saying so is a report that cannot be audited.
 *
 * • No foreign key on `cart_id`/`location_id` beyond nullOnDelete: retiring a cart must never
 *   delete last month's movement history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_location_pings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Denormalised from recorded_at so a day's trail is one indexed lookup rather than a
            // range scan over a datetime — the same reason every operational table here carries
            // its own operating_date.
            $table->date('operating_date');

            // Server clock (R16). The phone's own timestamp travels in `captured_at` when the
            // ping was queued offline, so a device with a wrong clock cannot move a sale into a
            // different hour of the analysis.
            $table->timestamp('recorded_at');
            $table->timestamp('captured_at')->nullable();

            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            // Accuracy is kept because a 2km-accurate fix must not be plotted as a place someone
            // stood. The UI dims anything above the display threshold rather than hiding it.
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->unsignedTinyInteger('battery_pct')->nullable();
            $table->boolean('is_moving')->nullable();

            $table->string('source', 16)->default('ping'); // ping | sale | absen | refill
            $table->foreignId('cart_id')->nullable()->constrained('carts')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('device_id')->nullable();

            $table->timestamps();

            // "Where is this person now" and "show me this person's day".
            $table->index(['user_id', 'recorded_at']);
            // "Show me everybody today" — the live map's only query.
            $table->index(['operating_date', 'user_id']);
            // The prune command's scan, and the area × hour aggregation.
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_location_pings');
    }
};
