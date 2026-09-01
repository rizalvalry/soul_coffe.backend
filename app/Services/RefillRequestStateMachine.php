<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Enums\SignatureMethod;
use App\Jobs\PostRefillStockLedger;
use App\Models\CentralKitchen;
use App\Models\Media;
use App\Models\Product;
use App\Models\RefillRequest;
use App\Models\RefillRequestLine;
use App\Models\RefillStatusHistory;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The single writer of `refill_requests.status` (docs/02 §6). Every guard in
 * the §6.1 transition table lives here — no controller, job, or model touches
 * `status` directly.
 *
 * Every public transition method:
 *   1. Locks the row (`lockForUpdate`) so guards are evaluated against a
 *      consistent snapshot, not a read that a concurrent writer can invalidate
 *      between the check and the write.
 *   2. Writes the new status, the matching refill_status_history row (R8),
 *      and publishes the realtime event — all inside one transaction, so an
 *      event can never describe a state that gets rolled back.
 *   3. Reports failures as either a 422 (ValidationException — the R4
 *      monotonic chain, malformed input) or a 409 (HttpException — a state
 *      guard refused the transition, including E1/E2's concurrency losers),
 *      matching the status-code table in docs/04.
 */
class RefillRequestStateMachine
{
    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly EventPublisher $events,
        private readonly RefillCodeGenerator $codes,
        private readonly MediaService $media,
    ) {}

    // ── SUBMIT ──────────────────────────────────────────────────────────────

    /**
     * @param  array{
     *     uuid: string, cart_id: int, evidence_media_id: int,
     *     gps_lat?: float|null, gps_lng?: float|null, gps_unavailable?: bool,
     *     client_submitted_at?: string|null, device_id?: string|null,
     *     lines: array<int, array{product_id: int, qty_requested: int}>,
     * }  $data
     */
    public function submit(User $staff, array $data, ?string $idempotencyKey): RefillRequest
    {
        // R14: a client-generated uuid dedupes even across a differing or
        // missing Idempotency-Key, so a flapping connection can never create
        // two requests for the same compose action.
        $existing = RefillRequest::query()->where('uuid', $data['uuid'])->first();
        if ($existing) {
            return $existing;
        }

        $cartId = (int) $data['cart_id'];
        $today = Carbon::today();

        $assignment = StaffAssignment::query()
            ->where('user_id', $staff->id)
            ->where('cart_id', $cartId)
            ->whereDate('operating_date', $today)
            ->first();

        if (! $assignment) {
            // R11 / §9.
            abort(403, 'Anda tidak bertugas di gerobak ini hari ini');
        }

        $evidence = Media::query()
            ->where('id', $data['evidence_media_id'])
            ->where('kind', 'evidence')
            ->first();

        if (! $evidence) {
            throw ValidationException::withMessages([
                'evidence_media_id' => ['Foto bukti tidak ditemukan atau tidak valid.'],
            ]);
        }

        $kitchenId = $assignment->kitchen_id;

        [$lineInputs, $totalCost] = $this->priceLines($data['lines']);

        $outOfHours = $this->isOutOfHours($assignment->kitchen);

        $refill = DB::transaction(function () use (
            $staff, $data, $cartId, $kitchenId, $today, $evidence,
            $lineInputs, $totalCost, $outOfHours, $idempotencyKey,
        ): RefillRequest {
            $refill = null;
            $lastException = null;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $code = $this->codes->generate($today);

                try {
                    $refill = RefillRequest::create([
                        'uuid' => $data['uuid'],
                        'code' => $code,
                        'operating_date' => $today,
                        'cart_id' => $cartId,
                        'staff_id' => $staff->id,
                        'kitchen_id' => $kitchenId,
                        'status' => RefillStatus::SUBMITTED,
                        'version' => 0,
                        'evidence_photo_id' => $evidence->id,
                        'gps_lat' => $data['gps_lat'] ?? null,
                        'gps_lng' => $data['gps_lng'] ?? null,
                        'gps_unavailable' => (bool) ($data['gps_unavailable'] ?? false),
                        'submitted_at' => now(),
                        'total_cost_minor' => $totalCost,
                        'price_version_id' => null,
                        'out_of_hours' => $outOfHours,
                        'client_submitted_at' => $data['client_submitted_at'] ?? null,
                        'device_id' => $data['device_id'] ?? null,
                        'idempotency_key' => $idempotencyKey,
                    ]);

                    break;
                } catch (\PDOException $e) {
                    // PDO can surface either a raw \PDOException or Laravel's wrapped
                    // QueryException/UniqueConstraintViolationException (itself a
                    // QueryException) depending on the failure path — catching the
                    // common ancestor covers both instead of silently missing one.
                    $lastException = $e;

                    if (! $this->isDuplicateKeyViolation($e)) {
                        throw $e;
                    }

                    if (str_contains($e->getMessage(), 'active_cart_id')) {
                        // R2's actual enforcement mechanism.
                        abort(409, 'Masih ada request yang belum selesai untuk gerobak ini.');
                    }

                    // Anything else unique-violating here is the `code` column —
                    // collided with another request minted the same instant, retry with a fresh one.
                    continue;
                }
            }

            if ($refill === null) {
                throw $lastException ?? new RuntimeException('Gagal membuat kode request setelah beberapa percobaan.');
            }

            foreach ($lineInputs as $line) {
                RefillRequestLine::create($line + ['refill_request_id' => $refill->id]);
            }

            $this->history($refill, null, RefillStatus::SUBMITTED, $staff, null, $data['device_id'] ?? null, $data['gps_lat'] ?? null, $data['gps_lng'] ?? null);

            $totalQty = collect($lineInputs)->sum('qty_requested');

            $this->events->publish(
                'RefillRequestSubmitted',
                'Request refill baru',
                "{$refill->code} · Gerobak {$refill->cart->code} · {$totalQty} cups",
                ['refill.'.$refill->id, 'role.FINANCE', 'kitchen.'.$kitchenId, 'cart.'.$cartId],
                array_merge($this->userIdsWithRole(Role::FINANCE), [$staff->id]),
                $refill->id,
                RefillStatus::SUBMITTED->value,
            );

            return $refill;
        });

        return $refill->fresh();
    }

    // ── APPROVE / REJECT / CANCEL ───────────────────────────────────────────

    /**
     * @param  array{version?: int|null, lines: array<int, array{line_id: int, qty_approved: int}>, partial_reason?: string|null}  $data
     */
    public function approve(RefillRequest $refill, User $finance, array $data, ?string $deviceId = null): RefillRequest
    {
        return DB::transaction(function () use ($refill, $finance, $data, $deviceId): RefillRequest {
            $locked = RefillRequest::query()->whereKey($refill->id)->lockForUpdate()->firstOrFail();

            $expectedVersion = $data['version'] ?? null;
            if ($expectedVersion !== null && (int) $expectedVersion !== $locked->version) {
                // E1: the loser of a concurrent approval.
                abort(409, 'Request ini sudah diperbarui oleh pengguna lain. Muat ulang data.');
            }

            if ($locked->status !== RefillStatus::SUBMITTED) {
                abort(409, 'Request tidak dalam status menunggu approval.');
            }

            $lines = $locked->lines()->get()->keyBy('id');
            $anyReduced = false;
            $anyApproved = false;

            foreach ($data['lines'] as $lineInput) {
                $line = $lines->get($lineInput['line_id']);

                if (! $line) {
                    throw ValidationException::withMessages(['lines' => ['Baris permintaan tidak ditemukan.']]);
                }

                $qtyApproved = (int) $lineInput['qty_approved'];

                if ($qtyApproved > $line->qty_requested) {
                    // R4.
                    throw ValidationException::withMessages([
                        'lines' => ['Jumlah approve tidak boleh melebihi permintaan'],
                    ]);
                }

                if ($qtyApproved < $line->qty_requested) {
                    $anyReduced = true;
                }

                if ($qtyApproved > 0) {
                    $anyApproved = true;
                }
            }

            if (! $anyApproved) {
                throw ValidationException::withMessages([
                    'lines' => ['Setidaknya satu baris harus disetujui.'],
                ]);
            }

            $partialReason = $data['partial_reason'] ?? null;

            if ($anyReduced && (blank($partialReason) || mb_strlen(trim((string) $partialReason)) < 10)) {
                throw ValidationException::withMessages([
                    'partial_reason' => ['Alasan pengurangan wajib diisi (min. 10 karakter)'],
                ]);
            }

            foreach ($data['lines'] as $lineInput) {
                $lines->get($lineInput['line_id'])->update(['qty_approved' => (int) $lineInput['qty_approved']]);
            }

            $locked->status = RefillStatus::APPROVED;
            $locked->version += 1;
            $locked->finance_id = $finance->id;
            $locked->decided_at = now();
            $locked->decision_reason = $partialReason;
            $locked->save();

            $this->history($locked, RefillStatus::SUBMITTED, RefillStatus::APPROVED, $finance, $partialReason, $deviceId);

            $this->events->publish(
                'RefillRequestApproved',
                'Permintaan disetujui Finance',
                "{$locked->code} · Gerobak {$locked->cart->code}",
                ['refill.'.$locked->id, 'kitchen.'.$locked->kitchen_id, 'cart.'.$locked->cart_id],
                array_merge($this->userIdsWithRole(Role::BARISTA, $locked->kitchen_id), [$locked->staff_id]),
                $locked->id,
                RefillStatus::APPROVED->value,
            );

            return $locked->fresh();
        });
    }

    public function reject(RefillRequest $refill, User $finance, string $reason, ?string $deviceId = null): RefillRequest
    {
        return DB::transaction(function () use ($refill, $finance, $reason, $deviceId): RefillRequest {
            $locked = RefillRequest::query()->whereKey($refill->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RefillStatus::SUBMITTED) {
                abort(409, 'Request tidak dalam status menunggu approval.');
            }

            $locked->status = RefillStatus::REJECTED;
            $locked->version += 1;
            $locked->finance_id = $finance->id;
            $locked->decided_at = now();
            $locked->decision_reason = $reason;
            $locked->save();

            $this->history($locked, RefillStatus::SUBMITTED, RefillStatus::REJECTED, $finance, $reason, $deviceId);

            $this->events->publish(
                'RefillRequestRejected',
                'Permintaan ditolak',
                "{$locked->code} · {$reason}",
                ['refill.'.$locked->id, 'cart.'.$locked->cart_id],
                [$locked->staff_id],
                $locked->id,
                RefillStatus::REJECTED->value,
            );

            return $locked->fresh();
        });
    }

    public function cancel(RefillRequest $refill, User $staff, ?string $deviceId = null): RefillRequest
    {
        return DB::transaction(function () use ($refill, $staff, $deviceId): RefillRequest {
            $locked = RefillRequest::query()->whereKey($refill->id)->lockForUpdate()->firstOrFail();

            if ($locked->staff_id !== $staff->id) {
                abort(403, 'Anda bukan pemilik request ini.');
            }

            if ($locked->status !== RefillStatus::SUBMITTED) {
                // E16: cancellation is not permitted once APPROVED — only Finance/Admin may abort past that point,
                // which is a separate, not-yet-in-scope operation, not this endpoint.
                abort(409, 'Request tidak bisa dibatalkan pada status ini.');
            }

            $locked->status = RefillStatus::CANCELLED;
            $locked->version += 1;
            $locked->save();

            $this->history($locked, RefillStatus::SUBMITTED, RefillStatus::CANCELLED, $staff, null, $deviceId);

            $this->events->publish(
                'RefillRequestCancelled',
                'Permintaan dibatalkan',
                (string) $locked->code,
                ['refill.'.$locked->id, 'role.FINANCE', 'kitchen.'.$locked->kitchen_id],
                $this->userIdsWithRole(Role::FINANCE),
                $locked->id,
                RefillStatus::CANCELLED->value,
            );

            return $locked->fresh();
        });
    }

    // ── PREPARE / READY ─────────────────────────────────────────────────────

    /**
     * R1 — the whole point of this application. Throws a 409 unless status is
     * exactly APPROVED. Not "not yet submitted", not "not rejected" — exactly
     * APPROVED. The mobile UI's disabled button is convenience only; this is
     * the actual gate.
     */
    public function startPreparing(RefillRequest $refill, User $barista, ?string $deviceId = null): RefillRequest
    {
        return DB::transaction(function () use ($refill, $barista, $deviceId): RefillRequest {
            $locked = RefillRequest::query()->whereKey($refill->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RefillStatus::APPROVED) {
                abort(409, 'Request belum disetujui Finance.');
            }

            if ($barista->kitchen_id !== $locked->kitchen_id) {
                abort(403, 'Barista tidak berada di dapur yang sesuai.');
            }

            $locked->status = RefillStatus::PREPARING;
            $locked->version += 1;
            $locked->barista_id = $barista->id;
            $locked->save();

            $this->history($locked, RefillStatus::APPROVED, RefillStatus::PREPARING, $barista, null, $deviceId);

            $this->events->publish(
                'RefillPreparingStarted',
                'Barista mulai menyiapkan',
                "{$locked->code} · Gerobak {$locked->cart->code}",
                ['refill.'.$locked->id, 'cart.'.$locked->cart_id],
                [$locked->staff_id],
                $locked->id,
                RefillStatus::PREPARING->value,
            );

            return $locked->fresh();
        });
    }

    /**
     * @param  array{lines: array<int, array{line_id: int, qty_prepared: int}>, shortfall_reason?: string|null}  $data
     */
    public function markReady(RefillRequest $refill, User $barista, array $data, ?string $deviceId = null): RefillRequest
    {
        return DB::transaction(function () use ($refill, $barista, $data, $deviceId): RefillRequest {
            $locked = RefillRequest::query()->whereKey($refill->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RefillStatus::PREPARING) {
                abort(409, 'Request tidak dalam status sedang disiapkan.');
            }

            $lines = $locked->lines()->get()->keyBy('id');
            $anyPrepared = false;
            $anyShortfall = false;

            foreach ($data['lines'] as $lineInput) {
                $line = $lines->get($lineInput['line_id']);

                if (! $line) {
                    throw ValidationException::withMessages(['lines' => ['Baris permintaan tidak ditemukan.']]);
                }

                $qtyPrepared = (int) $lineInput['qty_prepared'];
                $approved = $line->qty_approved ?? 0;

                if ($qtyPrepared > $approved) {
                    // R4 / E22.
                    throw ValidationException::withMessages([
                        'lines' => ['Jumlah siap tidak boleh melebihi yang di-approve'],
                    ]);
                }

                if ($qtyPrepared < $approved) {
                    $anyShortfall = true;
                }

                if ($qtyPrepared > 0) {
                    $anyPrepared = true;
                }
            }

            if (! $anyPrepared) {
                throw ValidationException::withMessages([
                    'lines' => ['Setidaknya satu baris harus disiapkan.'],
                ]);
            }

            $shortfallReason = $data['shortfall_reason'] ?? null;

            if ($anyShortfall && blank($shortfallReason)) {
                // E9.
                throw ValidationException::withMessages([
                    'shortfall_reason' => ['Alasan kekurangan stok wajib diisi.'],
                ]);
            }

            foreach ($data['lines'] as $lineInput) {
                $lines->get($lineInput['line_id'])->update(['qty_prepared' => (int) $lineInput['qty_prepared']]);
            }

            $locked->status = RefillStatus::READY_TO_PICK;
            $locked->version += 1;
            $locked->prepared_at = now();
            $locked->shortfall_reason = $shortfallReason;
            $locked->save();

            $this->history($locked, RefillStatus::PREPARING, RefillStatus::READY_TO_PICK, $barista, $shortfallReason, $deviceId);

            $this->events->publish(
                'RefillReadyToPick',
                'Siap diambil',
                "{$locked->code} · Gerobak {$locked->cart->code}",
                ['refill.'.$locked->id, 'role.RIDER', 'cart.'.$locked->cart_id],
                array_merge($this->userIdsWithRole(Role::RIDER), [$locked->staff_id]),
                $locked->id,
                RefillStatus::READY_TO_PICK->value,
            );

            return $locked->fresh();
        });
    }

    // ── CLAIM (E2) ───────────────────────────────────────────────────────────

    /**
     * E2 / §6.1: an atomic conditional UPDATE is the entire enforcement
     * mechanism. Two riders racing this method will produce exactly one
     * `$updated === 1` and one `$updated === 0` — there is no read-then-write
     * window for both to slip through, unlike a `lockForUpdate` + status
     * check (which would only serialise readers who already agree to wait).
     */
    public function claim(RefillRequest $refill, User $rider, ?string $deviceId = null): RefillRequest
    {
        return DB::transaction(function () use ($refill, $rider, $deviceId): RefillRequest {
            $updated = DB::table('refill_requests')
                ->where('id', $refill->id)
                ->where('status', RefillStatus::READY_TO_PICK->value)
                ->whereNull('rider_id')
                ->update([
                    'status' => RefillStatus::PICKED_UP->value,
                    'rider_id' => $rider->id,
                    'picked_up_at' => now(),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                abort(409, 'Sudah diambil rider lain.');
            }

            $fresh = RefillRequest::findOrFail($refill->id);

            $this->history($fresh, RefillStatus::READY_TO_PICK, RefillStatus::PICKED_UP, $rider, null, $deviceId);

            $this->events->publish(
                'RefillPickedUp',
                'Rider dalam perjalanan',
                "{$fresh->code} · Gerobak {$fresh->cart->code}",
                ['refill.'.$fresh->id, 'kitchen.'.$fresh->kitchen_id, 'cart.'.$fresh->cart_id],
                [$fresh->staff_id],
                $fresh->id,
                RefillStatus::PICKED_UP->value,
            );

            return $fresh;
        });
    }

    // ── DELIVER (R5, R13, E7, E8, E19, E24) ─────────────────────────────────

    /**
     * @param  array{
     *     lines: array<int, array{line_id: int, qty_received: int}>,
     *     signature_method: string, staff_pin?: string|null, staff_id?: int|null,
     *     stroke_count: int, gps_lat?: float|null, gps_lng?: float|null,
     *     gps_unavailable?: bool, device_id?: string|null,
     * }  $data
     * @return array{refill: RefillRequest, ledger_posted: bool}
     */
    public function deliver(RefillRequest $refill, User $rider, array $data, UploadedFile $signatureFile): array
    {
        $delivered = DB::transaction(function () use ($refill, $rider, $data, $signatureFile): RefillRequest {
            $locked = RefillRequest::query()->whereKey($refill->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RefillStatus::PICKED_UP) {
                abort(409, 'Request tidak dalam status sedang dikirim.');
            }

            if ($locked->rider_id !== $rider->id) {
                abort(403, 'Anda bukan rider yang mengambil request ini.');
            }

            // E8 — only checked when the client supplies who is physically present;
            // see MediaService/DeliverRefillRequestRequest docblocks for why this
            // field isn't in the abbreviated contract sample but is additive.
            $staffId = $data['staff_id'] ?? null;
            if ($staffId !== null && (int) $staffId !== $locked->staff_id) {
                abort(403, 'Staff tidak sesuai dengan pemohon');
            }

            $method = SignatureMethod::from($data['signature_method']);

            if ($method === SignatureMethod::PIN_FALLBACK) {
                $staff = $locked->staff;

                if (! $staff?->pin_hash || ! Hash::check((string) ($data['staff_pin'] ?? ''), $staff->pin_hash)) {
                    // E7.
                    throw ValidationException::withMessages(['staff_pin' => ['PIN staff tidak sesuai']]);
                }
            } else {
                $strokeCount = (int) ($data['stroke_count'] ?? 0);

                if ($strokeCount < 3) {
                    // E24 — a single accidental dot.
                    throw ValidationException::withMessages(['stroke_count' => ['Tanda tangan belum lengkap']]);
                }
            }

            // R13 is skipped only for the PIN-fallback placeholder — see MediaService::storeSignature().
            $signature = $this->media->storeSignature($signatureFile, $rider, $method !== SignatureMethod::PIN_FALLBACK);

            $lines = $locked->lines()->get()->keyBy('id');

            foreach ($data['lines'] as $lineInput) {
                $line = $lines->get($lineInput['line_id']);

                if (! $line) {
                    throw ValidationException::withMessages(['lines' => ['Baris permintaan tidak ditemukan.']]);
                }

                $qtyReceived = (int) $lineInput['qty_received'];
                $prepared = $line->qty_prepared ?? 0;

                if ($qtyReceived > $prepared) {
                    // R4.
                    throw ValidationException::withMessages([
                        'lines' => ['Jumlah diterima tidak boleh melebihi yang dikirim'],
                    ]);
                }
            }

            foreach ($data['lines'] as $lineInput) {
                $lines->get($lineInput['line_id'])->update(['qty_received' => (int) $lineInput['qty_received']]);
            }

            $locked->status = RefillStatus::DELIVERED;
            $locked->version += 1;
            $locked->delivered_at = now();
            $locked->signature_id = $signature->id;
            $locked->signature_method = $method;
            $locked->gps_lat = $data['gps_lat'] ?? $locked->gps_lat;
            $locked->gps_lng = $data['gps_lng'] ?? $locked->gps_lng;
            $locked->gps_unavailable = (bool) ($data['gps_unavailable'] ?? $locked->gps_unavailable);
            $locked->save();

            $this->history(
                $locked,
                RefillStatus::PICKED_UP,
                RefillStatus::DELIVERED,
                $rider,
                $method === SignatureMethod::PIN_FALLBACK ? 'PIN fallback digunakan (E7)' : null,
                $data['device_id'] ?? null,
                $data['gps_lat'] ?? null,
                $data['gps_lng'] ?? null,
            );

            $this->events->publish(
                'RefillDelivered',
                'Refill terkirim',
                "{$locked->code} · Gerobak {$locked->cart->code}",
                ['refill.'.$locked->id, 'kitchen.'.$locked->kitchen_id, 'cart.'.$locked->cart_id, 'role.FINANCE'],
                array_filter(array_merge(
                    $this->userIdsWithRole(Role::FINANCE),
                    $this->userIdsWithRole(Role::ADMINISTRATOR),
                    [$locked->staff_id, $locked->barista_id],
                )),
                $locked->id,
                RefillStatus::DELIVERED->value,
            );

            return $locked->fresh();
        });

        $ledgerPosted = true;

        try {
            $this->postLedger($delivered, $rider->id);
        } catch (Throwable $e) {
            report($e);
            $ledgerPosted = false;

            // E19: never let a retry failure surface into the HTTP response —
            // the request must stay DELIVERED and the endpoint must still
            // answer, whatever the queue driver does with the exception
            // (the `sync` driver used in tests rethrows it to the dispatcher).
            try {
                PostRefillStockLedger::dispatch($delivered->id, $rider->id);
            } catch (Throwable $dispatchError) {
                report($dispatchError);
            }
        }

        return ['refill' => $delivered->fresh(), 'ledger_posted' => $ledgerPosted];
    }

    // ── LEDGER POSTING (E19) ─────────────────────────────────────────────────

    /**
     * Posts the kitchen→cart stock transfer for a DELIVERED request and
     * closes it. Throws on failure so both call sites — the synchronous
     * attempt in deliver() and the retrying PostRefillStockLedger job — get a
     * real exception to react to; neither swallows it silently.
     *
     * Idempotent: a request already CLOSED is a silent no-op, so a retry that
     * races a previous successful attempt can never double-post.
     */
    public function postLedger(RefillRequest $refill, int $actorId): void
    {
        DB::transaction(function () use ($refill, $actorId): void {
            $locked = RefillRequest::query()->whereKey($refill->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === RefillStatus::CLOSED) {
                return;
            }

            if ($locked->status !== RefillStatus::DELIVERED) {
                throw new RuntimeException("Refill #{$locked->id} tidak dalam status DELIVERED, tidak bisa memposting ledger.");
            }

            foreach ($locked->lines()->get() as $line) {
                $qty = (int) ($line->qty_received ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                $this->ledger->transfer(
                    StockLedgerService::KITCHEN,
                    $locked->kitchen_id,
                    StockLedgerService::CART,
                    $locked->cart_id,
                    $line->product_id,
                    MovementType::REFILL_OUT,
                    MovementType::REFILL_IN,
                    $qty,
                    $actorId,
                    $locked->kitchen_id,
                    'refill_request',
                    $locked->id,
                );
            }

            $locked->status = RefillStatus::CLOSED;
            $locked->version += 1;
            $locked->save();

            $actor = User::query()->find($actorId) ?? $locked->rider ?? $locked->staff;

            $this->history($locked, RefillStatus::DELIVERED, RefillStatus::CLOSED, $actor, 'Stok berhasil diposting ke ledger');

            $this->events->publish(
                'RefillRequestClosed',
                'Refill selesai',
                "{$locked->code} · Gerobak {$locked->cart->code}",
                ['refill.'.$locked->id, 'kitchen.'.$locked->kitchen_id, 'cart.'.$locked->cart_id],
                [$locked->staff_id],
                $locked->id,
                RefillStatus::CLOSED->value,
            );
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{product_id: int, qty_requested: int}>  $lines
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function priceLines(array $lines): array
    {
        $productIds = collect($lines)->pluck('product_id')->unique()->values();
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        $lineInputs = [];
        $totalCost = 0;

        foreach ($lines as $line) {
            $product = $products->get($line['product_id']);

            if (! $product) {
                throw ValidationException::withMessages(['lines' => ['Produk tidak ditemukan.']]);
            }

            $priceVersion = $product->currentPriceVersion();

            if (! $priceVersion) {
                throw ValidationException::withMessages(['lines' => ["Harga produk {$product->name} belum tersedia."]]);
            }

            $qty = (int) $line['qty_requested'];
            $unitCost = $priceVersion->cost_price_minor;
            $lineCost = $unitCost * $qty;
            $totalCost += $lineCost;

            $lineInputs[] = [
                'product_id' => $product->id,
                'qty_requested' => $qty,
                'unit_cost_minor' => $unitCost,
                'line_cost_minor' => $lineCost,
            ];
        }

        return [$lineInputs, $totalCost];
    }

    /**
     * E12: never a hard block, only a flag carried on the request for
     * visibility. $kitchen may be null in defensive callers; falls back to
     * the global config default.
     */
    private function isOutOfHours(?CentralKitchen $kitchen): bool
    {
        $open = $kitchen->open_at ?? config('soul.operating_open', '06:00');
        $close = $kitchen->close_at ?? config('soul.operating_close', '21:00');

        $open = strlen((string) $open) === 5 ? $open.':00' : $open;
        $close = strlen((string) $close) === 5 ? $close.':00' : $close;

        $now = now()->format('H:i:s');

        return $now < $open || $now > $close;
    }

    private function history(
        RefillRequest $refill,
        ?RefillStatus $from,
        RefillStatus $to,
        User $actor,
        ?string $reason,
        ?string $deviceId = null,
        $gpsLat = null,
        $gpsLng = null,
    ): void {
        RefillStatusHistory::create([
            'refill_request_id' => $refill->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
            'actor_role' => $actor->role instanceof Role ? $actor->role->value : (string) $actor->role,
            'reason' => $reason,
            'device_id' => $deviceId,
            'gps_lat' => $gpsLat,
            'gps_lng' => $gpsLng,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function userIdsWithRole(Role $role, ?int $kitchenId = null): array
    {
        $query = User::query()->where('role', $role);

        if ($kitchenId !== null) {
            $query->where('kitchen_id', $kitchenId);
        }

        return $query->pluck('id')->all();
    }

    private function isDuplicateKeyViolation(\PDOException $e): bool
    {
        return (string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
