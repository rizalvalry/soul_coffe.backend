<?php

namespace App\Models;

use App\Enums\RefillStatus;
use App\Enums\SignatureMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * R2 — "one open refill request per cart" — is enforced by the DB-level unique
 * index on `active_cart_id` (see the create_refill_requests_table migration for
 * the full rationale: MySQL 8 cannot do a partial/filtered unique index).
 *
 * This model keeps `active_cart_id` in sync with `status` on every save, as a
 * safety net so the invariant holds even if a future caller sets `status`
 * directly instead of going through RefillRequestStateMachine (which owns the
 * actual transition guards and is out of scope here). Do not remove this
 * syncing or the unique index — see TERMINAL_STATUSES below.
 */
class RefillRequest extends Model
{
    use HasFactory;

    /**
     * Statuses that release the cart lock.
     *
     * The authoritative rule is RefillStatus::isOpen() — R2 defines "open" as SUBMITTED
     * through PICKED_UP — and syncActiveCartId() derives from that single source rather than
     * this list, so the two can never drift apart.
     *
     * DELIVERED releases the lock deliberately. If the ledger post fails, E19 requires the
     * request to stay DELIVERED while a queued job retries. Holding the lock through that
     * window would leave the cart unable to request a refill even though the cups have
     * physically arrived and been signed for — punishing field staff for an internal retry.
     *
     * This list is kept only for readability and for callers that want the explicit set.
     */
    public const TERMINAL_STATUSES = [
        RefillStatus::REJECTED,
        RefillStatus::CANCELLED,
        RefillStatus::EXPIRED,
        RefillStatus::DELIVERED,
        RefillStatus::CLOSED,
    ];

    protected $fillable = [
        'uuid',
        'code',
        'operating_date',
        'cart_id',
        'staff_id',
        'kitchen_id',
        'status',
        'version',
        'evidence_photo_id',
        'gps_lat',
        'gps_lng',
        'gps_unavailable',
        'submitted_at',
        'decided_at',
        'finance_id',
        'decision_reason',
        'barista_id',
        'prepared_at',
        'shortfall_reason',
        'rider_id',
        'picked_up_at',
        'delivered_at',
        'signature_id',
        'signature_method',
        'total_cost_minor',
        'price_version_id',
        'out_of_hours',
        'client_submitted_at',
        'device_id',
        'idempotency_key',
        'active_cart_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (RefillRequest $request) {
            $request->syncActiveCartId();
        });
    }

    /**
     * Keep active_cart_id consistent with status/cart_id (see class docblock).
     * Idempotent — safe to call any number of times.
     */
    public function syncActiveCartId(): void
    {
        if (! $this->status instanceof RefillStatus) {
            return;
        }

        // Derived from isOpen() so the lock rule has exactly one definition (R2).
        $this->active_cart_id = $this->status->isOpen() ? $this->cart_id : null;
    }

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'status' => RefillStatus::class,
            'signature_method' => SignatureMethod::class,
            'version' => 'integer',
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'gps_unavailable' => 'boolean',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'prepared_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'total_cost_minor' => 'integer',
            'out_of_hours' => 'boolean',
            'client_submitted_at' => 'datetime',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(CentralKitchen::class, 'kitchen_id');
    }

    public function evidencePhoto(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'evidence_photo_id');
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'signature_id');
    }

    public function finance(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_id');
    }

    public function barista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barista_id');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RefillRequestLine::class, 'refill_request_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(RefillStatusHistory::class, 'refill_request_id');
    }
}
