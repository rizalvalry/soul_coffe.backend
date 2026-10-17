<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Models\RefillExpiryDecision;
use App\Models\RefillRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefillExpiryDecisionService
{
    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly SettlementService $settlements,
    ) {}

    /**
     * @param  array<int, array{line_id: int, qty_recognized: int}>  $lines
     */
    public function decide(RefillRequest $refill, User $actor, string $outcome, string $reason, array $lines = []): RefillExpiryDecision
    {
        abort_unless(in_array($actor->role, [Role::FINANCE, Role::MANAGEMENT], true), 403);

        if (! in_array($outcome, ['failed', 'recognized'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Keputusan tidak dikenal.']);
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib minimal 10 karakter.']);
        }

        return DB::transaction(function () use ($refill, $actor, $outcome, $reason, $lines): RefillExpiryDecision {
            $locked = RefillRequest::query()->whereKey($refill->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RefillStatus::EXPIRED) {
                abort(409, 'Hanya request kedaluwarsa yang dapat diputuskan.');
            }

            $recorded = $locked->lines()->get()->keyBy('id');
            $quantities = [];
            $confirmedPrepared = [];
            foreach ($lines as $line) {
                $id = (int) ($line['line_id'] ?? 0);
                $qty = $line['qty_recognized'] ?? null;
                $source = $recorded->get($id);
                $prepared = $line['qty_prepared_confirmed'] ?? $source?->qty_prepared ?? 0;
                if (! $source || isset($quantities[$id]) || ! is_numeric($qty)
                    || filter_var($qty, FILTER_VALIDATE_INT) === false
                    || filter_var($prepared, FILTER_VALIDATE_INT) === false
                    || (int) $qty < 0 || (int) $prepared < 0
                    || (int) $prepared > (int) ($source->qty_approved ?? 0)
                    || ($source->qty_prepared !== null && (int) $prepared > (int) $source->qty_prepared)
                    || (int) $qty > (int) $prepared) {
                    throw ValidationException::withMessages(['lines' => 'Jumlah diakui harus bilangan bulat dan tidak melebihi jumlah disiapkan per baris.']);
                }
                $quantities[$id] = (int) $qty;
                $confirmedPrepared[$id] = (int) $prepared;
            }

            if ($outcome === 'recognized' && array_sum($quantities) <= 0) {
                throw ValidationException::withMessages(['lines' => 'Isi setidaknya satu cup yang diakui.']);
            }
            if ($outcome === 'failed' && array_sum($quantities) > 0) {
                throw ValidationException::withMessages(['lines' => 'Keputusan gagal tidak dapat mengakui cup.']);
            }

            $existing = $locked->expiryDecision()->first();
            if ($existing) {
                if ($existing->outcome !== $outcome || $existing->decided_by !== $actor->id
                    || $existing->reason !== trim($reason)
                    || $existing->recognized_lines != $quantities
                    || $existing->confirmed_prepared_lines != $confirmedPrepared) {
                    abort(409, 'Request kedaluwarsa ini sudah diputuskan dengan rincian berbeda.');
                }

                return $existing;
            }

            $decision = RefillExpiryDecision::query()->create([
                'refill_request_id' => $locked->id,
                'decided_by' => $actor->id,
                'outcome' => $outcome,
                'reason' => trim($reason),
                'recognized_lines' => $quantities,
                'confirmed_prepared_lines' => $confirmedPrepared,
                'decided_at' => now(),
            ]);

            // Ordinary refill delivery posts only a kitchen-to-cart stock transfer, no journal.
            // This decision posts the same pair for cups Finance confirms physically arrived.
            $occurredAt = $locked->picked_up_at ?? $locked->prepared_at ?? $locked->operating_date->endOfDay();
            foreach ($quantities as $id => $qty) {
                if ($qty === 0) {
                    continue;
                }
                $line = $recorded->get($id);
                $this->ledger->post(StockLedgerService::KITCHEN, $locked->kitchen_id,
                    $line->product_id, MovementType::REFILL_OUT, $qty, $actor->id,
                    $locked->kitchen_id, 'refill_request', $locked->id,
                    note: 'Keputusan Finance kedaluwarsa #'.$decision->id,
                    occurredAt: $occurredAt);
                $this->ledger->post(StockLedgerService::CART, $locked->cart_id,
                    $line->product_id, MovementType::REFILL_IN, $qty, $actor->id,
                    $locked->kitchen_id, 'refill_request', $locked->id,
                    note: 'Keputusan Finance kedaluwarsa #'.$decision->id,
                    occurredAt: $occurredAt);
            }

            if (array_sum($quantities) > 0) {
                // A decision can arrive after Finance approved the old day's settlement.
                // Keep its snapshot/journal intact, but flag it and notify Finance so the
                // newly recognized physical stock gets an explicit follow-up disposition.
                $this->settlements->markExpectedStale(
                    $locked->cart_id, $locked->operating_date, $actor, 0, stockCorrectionNeeded: true,
                );
            }

            return $decision;
        });
    }
}
