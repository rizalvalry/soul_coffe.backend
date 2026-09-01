<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refill_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // client-generated, R14 dedupe on flaky connections
            $table->string('code')->unique(); // e.g. REF-20260901-0003

            $table->date('operating_date');
            $table->foreignId('cart_id')->constrained('carts')->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('kitchen_id')->constrained('central_kitchens')->restrictOnDelete();

            $table->string('status'); // RefillStatus
            $table->unsignedInteger('version')->default(0); // optimistic lock, E1

            $table->foreignId('evidence_photo_id')->constrained('media')->restrictOnDelete(); // R3
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->boolean('gps_unavailable')->default(false); // E10 — never a hard block

            $table->timestamp('submitted_at')->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->foreignId('finance_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable(); // rejection_reason or partial_reason

            $table->foreignId('barista_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->text('shortfall_reason')->nullable(); // E9

            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->foreignId('signature_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('signature_method')->nullable(); // SignatureMethod

            // R9: BIGINT rupiah, scale = 0. R10: Sigma(qty * cost_price) pinned at submit
            // time from the price version in effect then — later price changes never
            // retroactively alter a decided request.
            $table->unsignedBigInteger('total_cost_minor')->default(0);
            // Kept for schema fidelity with §12. NOT an FK: a request spans several
            // products, each pinned to its own product_price_versions row (see
            // refill_request_lines.unit_cost_minor, which is what R10 actually enforces
            // per line). This column has no single row it can consistently reference.
            $table->unsignedBigInteger('price_version_id')->nullable();

            $table->boolean('out_of_hours')->default(false); // E12
            $table->timestamp('client_submitted_at')->nullable();
            $table->string('device_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique(); // R12, E3

            // R2 — "one open refill request per cart" without a partial unique index
            // (MySQL 8 cannot express UNIQUE(cart_id) WHERE status IN (...)).
            //
            // Holds `cart_id` while this request is OPEN (SUBMITTED..PICKED_UP, see
            // RefillStatus::isOpen()) and MUST be set back to NULL by the state machine
            // the moment the request reaches a terminal state: REJECTED, CANCELLED,
            // EXPIRED, or CLOSED. MySQL's unique index permits unlimited NULLs, so at
            // most one row per cart can ever hold a non-null value here — that is the
            // entire enforcement mechanism for R2.
            //
            // DO NOT remove this column or its unique index "because it looks
            // redundant with cart_id" — without it R2 is enforced nowhere at the
            // database level, only in application code that a future change can bypass.
            $table->unsignedBigInteger('active_cart_id')->nullable()->unique();

            $table->timestamps();

            // KDS cursor query: a kitchen's board paginated by status, ordered by
            // last update.
            $table->index(['kitchen_id', 'status', 'updated_at']);
            $table->index(['cart_id', 'status', 'updated_at']);
            $table->index(['operating_date', 'cart_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refill_requests');
    }
};
