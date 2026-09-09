<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\MovementType;
use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Models\DeliveryIncident;
use App\Models\DeliveryIncidentLine;
use App\Models\RefillRequest;
use App\Models\RefillStatusHistory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of `delivery_incidents`. Cups damaged between the kitchen and a cart.
 *
 * TWO ACTS, TWO ACTORS
 * --------------------
 * The rider REPORTS: a photo, and how many cups of which line were lost. That is all a person
 * standing over a spilled crate should have to do.
 *
 * Finance or an Administrator DECIDES, with exactly two outcomes, which are the two things that
 * can physically happen next:
 *
 *   cancel  — the run is called off and the rider returns to the kitchen. Used when what
 *             survived is not worth delivering.
 *   partial — the surviving cups still go out. Each affected line's `qty_prepared` comes down by
 *             the damaged amount, so the delivery that follows is measured against what is
 *             actually on the bike, and R4 (received ≤ prepared) keeps holding.
 *
 * WHAT HAPPENS TO THE STOCK, EITHER WAY
 * -------------------------------------
 * The damaged cups are written off from the KITCHEN with a WASTE_OUT movement. That is where
 * they still are as far as the ledger is concerned — a refill's kitchen→cart transfer is posted
 * at delivery, not at pickup — so writing them off anywhere else would invent stock that was
 * never there. The surviving cups need no movement at all: they are still the kitchen's until
 * they arrive.
 *
 * WHO IS TOLD
 * -----------
 * Administrator, Finance, the kitchen that brewed them, the rider, and the staff member waiting
 * for the delivery — that last one by their own user channel. Deliberately NOT `role.STAFF`: a
 * staff member at another cart has nothing to do with this accident, and telling all of them was
 * ruled out explicitly.
 */
class DeliveryIncidentService
{
    public function __construct(
        private readonly MediaService $media,
        private readonly StockLedgerService $ledger,
        private readonly EventPublisher $events,
    ) {}

    /**
     * @param  array<int, array{line_id: int, qty_damaged: int}>  $lines
     */
    public function report(
        RefillRequest $refill,
        User $rider,
        array $lines,
        UploadedFile $photo,
        Carbon $photoTakenAt,
        string $uuid,
        ?string $note = null,
        ?string $deviceId = null,
        ?string $idempotencyKey = null,
    ): DeliveryIncident {
        return DB::transaction(function () use ($refill, $rider, $lines, $photo, $photoTakenAt, $uuid, $note, $deviceId, $idempotencyKey): DeliveryIncident {
            $locked = RefillRequest::query()->whereKey($refill->id)->lockForUpdate()->firstOrFail();

            // A replayed submit returns the report that already exists rather than filing a
            // second one (R14). Checked inside the transaction so two replays cannot both pass.
            $existing = DeliveryIncident::query()->where('uuid', $uuid)->first();

            if ($existing) {
                return $existing->load('lines');
            }

            if ($locked->rider_id !== $rider->id) {
                abort(403, 'Anda bukan rider yang mengambil request ini.');
            }

            // Only in transit. Before pickup the cups are in the kitchen and this is the
            // barista's waste to record; after delivery it is the cart's, and the close-out
            // already has a reject column for it.
            if ($locked->status !== RefillStatus::PICKED_UP) {
                abort(409, 'Insiden hanya bisa dilaporkan saat pesanan sedang diantar.');
            }

            if (DeliveryIncident::query()
                ->where('refill_request_id', $locked->id)
                ->where('status', IncidentStatus::REPORTED)
                ->exists()
            ) {
                abort(409, 'Sudah ada laporan insiden yang menunggu keputusan untuk pengiriman ini.');
            }

            $refillLines = $locked->lines()->get()->keyBy('id');
            $merged = [];

            foreach ($lines as $input) {
                $lineId = (int) ($input['line_id'] ?? 0);
                $qty = (int) ($input['qty_damaged'] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                $line = $refillLines->get($lineId);

                if (! $line) {
                    throw ValidationException::withMessages([
                        'lines' => ['Baris permintaan tidak ditemukan.'],
                    ]);
                }

                $merged[$lineId] = ($merged[$lineId] ?? 0) + $qty;

                if ($merged[$lineId] > (int) ($line->qty_prepared ?? 0)) {
                    throw ValidationException::withMessages([
                        'lines' => ['Jumlah rusak tidak boleh melebihi yang dikirim.'],
                    ]);
                }
            }

            if ($merged === []) {
                throw ValidationException::withMessages([
                    'lines' => ['Isi dulu jumlah cups yang rusak.'],
                ]);
            }

            $media = $this->media->storeIncidentPhoto($photo, $photoTakenAt, $rider);

            $incident = DeliveryIncident::query()->create([
                'uuid' => $uuid,
                'refill_request_id' => $locked->id,
                'rider_id' => $rider->id,
                'photo_media_id' => $media->id,
                // Server clock (R16).
                'reported_at' => now(),
                'note' => $note,
                'status' => IncidentStatus::REPORTED,
                'device_id' => $deviceId,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($merged as $lineId => $qty) {
                DeliveryIncidentLine::query()->create([
                    'delivery_incident_id' => $incident->id,
                    'refill_request_line_id' => $lineId,
                    'product_id' => $refillLines->get($lineId)->product_id,
                    'qty_damaged' => $qty,
                ]);
            }

            $damaged = array_sum($merged);

            $this->events->publish(
                'DeliveryIncidentReported',
                'Insiden pengiriman',
                sprintf(
                    '%s · %d cups rusak dalam perjalanan. Menunggu keputusan Finance/Administrator.',
                    (string) $locked->code,
                    $damaged,
                ),
                $this->channelsFor($locked),
                $this->recipientsFor($locked, $rider),
                $locked->id,
                $locked->status->value,
            );

            return $incident->load('lines');
        });
    }

    /**
     * @param  'cancel'|'partial'  $mode
     */
    public function resolve(
        DeliveryIncident $incident,
        User $decider,
        string $mode,
        ?string $note = null,
    ): DeliveryIncident {
        if (! in_array($decider->role, [Role::FINANCE, Role::ADMINISTRATOR], true)) {
            abort(403, 'Hanya Finance atau Administrator yang memutuskan insiden.');
        }

        return DB::transaction(function () use ($incident, $decider, $mode, $note): DeliveryIncident {
            $locked = DeliveryIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                abort(409, 'Insiden ini sudah diputuskan.');
            }

            $refill = RefillRequest::query()->whereKey($locked->refill_request_id)->lockForUpdate()->firstOrFail();
            $lines = $locked->lines()->get();
            $refillLines = $refill->lines()->get()->keyBy('id');
            $writtenOff = 0;

            foreach ($lines as $line) {
                $qty = (int) $line->qty_damaged;

                if ($qty <= 0) {
                    continue;
                }

                // The cups are still the kitchen's in the ledger until a delivery posts the
                // transfer, so this is where the loss belongs.
                $this->ledger->post(
                    locationType: StockLedgerService::KITCHEN,
                    locationId: (int) $refill->kitchen_id,
                    productId: (int) $line->product_id,
                    movementType: MovementType::WASTE_OUT,
                    qty: $qty,
                    actorId: $decider->id,
                    kitchenId: (int) $refill->kitchen_id,
                    refType: 'delivery_incident',
                    refId: $locked->id,
                );

                $writtenOff += $qty;

                if ($mode === 'partial') {
                    $refillLine = $refillLines->get($line->refill_request_line_id);

                    if ($refillLine) {
                        // What is actually still on the bike. Without this the delivery that
                        // follows would be measured against cups that no longer exist, and R4
                        // would let the rider record receiving them.
                        $refillLine->qty_prepared = max(0, (int) $refillLine->qty_prepared - $qty);
                        $refillLine->save();
                    }
                }
            }

            if ($mode === 'cancel') {
                $from = $refill->status;
                $refill->status = RefillStatus::CANCELLED;
                $refill->version += 1;
                $refill->save();

                RefillStatusHistory::create([
                    'refill_request_id' => $refill->id,
                    'from_status' => $from,
                    'to_status' => RefillStatus::CANCELLED,
                    'actor_id' => $decider->id,
                    'actor_role' => $decider->role->value,
                    'reason' => 'Insiden pengiriman: '.($note ?: 'pengantaran dibatalkan, rider kembali ke dapur'),
                ]);
            } else {
                $reason = 'Insiden pengiriman: '.$writtenOff.' cups rusak dipisahkan, sisanya tetap diantar.';

                $refill->shortfall_reason = $note
                    ? $reason.' '.$note
                    : $reason;
                $refill->save();
            }

            $locked->status = $mode === 'cancel'
                ? IncidentStatus::RESOLVED_CANCELLED
                : IncidentStatus::RESOLVED_PARTIAL;
            $locked->decided_by = $decider->id;
            $locked->decided_at = now();
            $locked->decision_note = $note;
            $locked->written_off_qty = $writtenOff;
            $locked->save();

            $this->events->publish(
                $mode === 'cancel' ? 'DeliveryIncidentCancelled' : 'DeliveryIncidentPartial',
                $mode === 'cancel' ? 'Pengantaran dibatalkan' : 'Pengantaran lanjut sebagian',
                $mode === 'cancel'
                    ? sprintf('%s · %d cups rusak. Rider kembali ke dapur.', (string) $refill->code, $writtenOff)
                    : sprintf('%s · %d cups rusak dipisahkan, sisanya tetap diantar.', (string) $refill->code, $writtenOff),
                $this->channelsFor($refill),
                $this->recipientsFor($refill, $locked->rider),
                $refill->id,
                $refill->status->value,
            );

            return $locked->fresh(['lines']);
        });
    }

    /**
     * @return array<int, string>
     */
    private function channelsFor(RefillRequest $refill): array
    {
        return array_values(array_filter([
            'refill.'.$refill->id,
            'kitchen.'.$refill->kitchen_id,
            'role.ADMINISTRATOR',
            'role.FINANCE',
            // The one staff member this concerns, by name. `role.STAFF` would tell every cart in
            // the city about an accident on somebody else's route.
            $refill->staff_id ? 'user.'.$refill->staff_id : null,
            $refill->rider_id ? 'user.'.$refill->rider_id : null,
        ]));
    }

    /**
     * @return array<int, int>
     */
    private function recipientsFor(RefillRequest $refill, ?User $rider): array
    {
        $supervisors = User::query()
            ->whereIn('role', [Role::ADMINISTRATOR, Role::FINANCE])
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $kitchenBaristas = User::query()
            ->where('role', Role::BARISTA)
            ->where('kitchen_id', $refill->kitchen_id)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_filter(array_merge(
            $supervisors,
            $kitchenBaristas,
            [$refill->staff_id, $rider?->id ?? $refill->rider_id],
        ))));
    }
}
