<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cups damaged on the way to a cart — spilled, cracked, leaking after a fall.
 *
 * WHY THIS IS A RECORD AND NOT A STATUS
 * -------------------------------------
 * An accident is a thing that happened, with a photograph, a rider's name, and a count of what
 * was lost. Squeezing it into the refill's own status column would answer "what state is this
 * request in" and destroy every other question worth asking: how often does it happen, on which
 * route, how many cups a month, and who decided what to do about it.
 *
 * WHY THE RIDER DOES NOT DECIDE
 * -----------------------------
 * The rider reports; Finance or an Administrator decides whether the run is cancelled or the
 * good cups still go out. That was explicit in the request, and it is also the only arrangement
 * that survives scrutiny: the person whose delivery it is should not be the person who rules on
 * their own accident, and the decision has money in it either way.
 *
 * Nothing here is deleted. `restrictOnDelete` on the photo is the same rule the evidence photos
 * live under: a record of a loss that can lose its own evidence is not a record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique(); // client-generated, R14

            $table->foreignId('refill_request_id')->constrained('refill_requests')->cascadeOnDelete();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();

            // Required at the boundary (StoreDeliveryIncidentRequest). Nullable here only so the
            // foreign key can be restrictOnDelete rather than the column being unwritable.
            $table->foreignId('photo_media_id')->nullable()->constrained('media')->restrictOnDelete();

            $table->timestamp('reported_at');
            $table->text('note')->nullable();

            // REPORTED | RESOLVED_CANCELLED | RESOLVED_PARTIAL — see App\Enums\IncidentStatus.
            $table->string('status', 32)->default('REPORTED');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // How many cups were written off by the decision, kept on the row so a report does
            // not have to re-derive it from the lines and the decision together.
            $table->unsignedInteger('written_off_qty')->default(0);

            $table->string('device_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            // "What is waiting for a decision" — the only query the panel opens with.
            $table->index(['status', 'reported_at']);
            $table->index('refill_request_id');
        });

        Schema::create('delivery_incident_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_incident_id')->constrained('delivery_incidents')->cascadeOnDelete();

            // Which line of the refill was hit, so the decision can reduce exactly that line
            // rather than guessing from the product.
            $table->foreignId('refill_request_line_id')->constrained('refill_request_lines')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->unsignedInteger('qty_damaged');

            // One row per affected line: two reports of the same line in one incident would be
            // two answers to "how many were lost".
            $table->unique(['delivery_incident_id', 'refill_request_line_id'], 'incident_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_incident_lines');
        Schema::dropIfExists('delivery_incidents');
    }
};
