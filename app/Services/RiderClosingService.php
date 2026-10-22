<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Product;
use App\Models\RefillRequest;
use App\Models\RiderClosing;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RiderClosingService
{
    public function __construct(
        private readonly RiderDayGate $dayGate,
        private readonly StockLedgerService $ledger,
        private readonly EventPublisher $events,
        private readonly SettlementService $settlements,
        private readonly JournalService $journals,
        private readonly RefillRequestStateMachine $refills,
    ) {}

    public function assignment(User $rider): StaffAssignment
    {
        $this->dayGate->assertReady($rider, Carbon::today(), 'Absen dulu sebelum Closing ya.', allowClosed: true);

        $assignment = StaffAssignment::query()
            ->where('user_id', $rider->id)
            ->whereDate('operating_date', today()->toDateString())
            ->where('source', 'hand_to_cart')
            ->whereNull('superseded_at')
            ->latest('id')->first();

        if (! $assignment) {
            throw new HttpResponseException(response()->json([
                'code' => 'NO_CART_TODAY',
                'message' => 'Produksi belum kasih stok ke Coffee Bike kamu hari ini. Minta Produksi Add Stock dulu ya.',
            ], 403));
        }

        return $assignment;
    }

    /** Input is posted handover/refill plus older office-sale voids restored today, never a Rider field. */
    public function quantities(Cart $cart, Carbon $date): array
    {
        $day = $date->toDateString();
        $incoming = StockLedger::query()
            ->where('location_type', StockLedgerService::CART)
            ->where('location_id', $cart->id)
            ->whereDate('created_at', $day)
            ->whereIn('movement_type', [MovementType::ALLOCATION_IN->value, MovementType::REFILL_IN->value])
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_delta) AS qty')
            ->pluck('qty', 'product_id')->map(fn ($qty): int => (int) $qty)->all();
        // A late void of an older office sale restores cups today, after yesterday's carry cutoff.
        $lateOfficeVoids = DB::table('stock_ledger as ledger')
            ->join('direct_sales as sales', 'sales.id', '=', 'ledger.ref_id')
            ->where('ledger.location_type', StockLedgerService::CART)
            ->where('ledger.location_id', $cart->id)
            ->whereDate('ledger.created_at', $day)
            ->where('ledger.ref_type', 'direct_sale_void')
            ->where('sales.occurred_at', '<', $date->copy()->startOfDay())
            ->groupBy('ledger.product_id')
            ->selectRaw('ledger.product_id, SUM(ledger.qty_delta) AS qty')
            ->pluck('qty', 'product_id');
        foreach ($lateOfficeVoids as $productId => $qty) {
            $incoming[$productId] = ($incoming[$productId] ?? 0) + (int) $qty;
        }
        $carry = StockLedger::query()
            ->where('location_type', StockLedgerService::CART)
            ->where('location_id', $cart->id)
            ->where('created_at', '<', $date->copy()->startOfDay())
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_delta) AS qty')
            ->pluck('qty', 'product_id')->map(fn ($qty): int => max(0, (int) $qty))->all();

        return [$incoming, $carry];
    }

    /** Office sales already posted SALE_OUT and must not become Rider sales. */
    private function officeSold(Cart $cart, Carbon $date): array
    {
        $start = $date->copy()->startOfDay();

        return DB::table('direct_sale_lines as lines')
            ->join('direct_sales as sales', 'sales.id', '=', 'lines.direct_sale_id')
            ->where('sales.cart_id', $cart->id)
            ->where('sales.occurred_at', '>=', $start)
            ->where('sales.occurred_at', '<', $start->copy()->addDay())
            ->whereNull('sales.voided_at')
            ->groupBy('lines.product_id')
            ->selectRaw('lines.product_id, SUM(lines.qty) AS qty')
            ->pluck('qty', 'product_id')->map(fn ($qty): int => (int) $qty)->all();
    }

    public function draft(User $rider): array
    {
        $assignment = $this->assignment($rider);
        $cart = Cart::query()->findOrFail($assignment->cart_id);
        $date = Carbon::today();
        [$incoming, $carry] = $this->quantities($cart, $date);
        $officeSold = $this->officeSold($cart, $date);
        $existing = RiderClosing::query()->with('lines')
            ->where('cart_id', $cart->id)->whereDate('operating_date', $date->toDateString())->first();
        $ids = array_unique([...array_keys($incoming), ...array_keys($carry)]);
        $products = Product::query()->whereIn('id', $ids)->orderBy('sort_order')->get();
        $lines = $products->map(function (Product $product) use ($incoming, $carry, $officeSold, $existing): array {
            $prior = $existing?->lines->firstWhere('product_id', $product->id);
            $price = $product->priceVersionAt(now());

            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'qty_in' => $incoming[$product->id] ?? 0,
                'qty_carry' => $carry[$product->id] ?? 0,
                'qty_office_sold' => $officeSold[$product->id] ?? 0,
                'qty_remaining' => $prior?->qty_remaining ?? 0,
                'qty_wasted' => $prior?->qty_wasted ?? 0,
                'qty_sold' => $prior?->qty_sold ?? 0,
                'unit_price_minor' => $prior?->unit_price_minor ?? (int) ($price?->sell_price_minor ?? 0),
                'subtotal_minor' => $prior?->subtotal_minor ?? 0,
            ];
        })->values()->all();
        $settlement = Settlement::query()->where('cart_id', $cart->id)
            ->whereDate('operating_date', $date->toDateString())->first();

        return [
            'cart_id' => $cart->id,
            'cart_code' => $cart->code,
            'operating_date' => $date->toDateString(),
            'closing_id' => $existing?->id,
            'can_correct' => ! $settlement?->reconciled_at,
            'carry_warning' => collect($carry)->contains(fn (int $qty): bool => $qty > 0),
            'total_minor' => $existing?->total_minor ?? 0,
            'qris_minor' => $existing?->qris_minor ?? 0,
            'transfer_minor' => $existing?->transfer_minor ?? 0,
            'cash_minor' => $existing?->cash_minor ?? 0,
            'closing_point' => app(RiderClosingPoint::class)->for($rider, $assignment),
            'outside_closing_point' => (bool) ($existing?->outside_closing_point ?? false),
            'closing_distance_m' => $existing?->closing_distance_m,
            'lines' => $lines,
            'commission' => app(RiderCommissionService::class)->day($rider->id, $date->toDateString()),
        ];
    }

    /**
     * A revision preserves the complete previous/new snapshot. Stock corrections are new ledger
     * rows; no old SALE_OUT or WASTE_OUT row is ever changed or removed.
     *
     * @param  array<int,array{product_id:int,qty_remaining:int,qty_wasted:int}>  $input
     */
    public function record(User $actor, Cart $cart, User $rider, Carbon $date, array $input, int $qris, int $transfer, array $gps, ?string $reason = null, ?string $idempotencyKey = null): RiderClosing
    {
        return DB::transaction(function () use ($actor, $cart, $rider, $date, $input, $qris, $transfer, $gps, $reason, $idempotencyKey): RiderClosing {
            Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $requestHash = hash('sha256', json_encode([
                $actor->id, $cart->id, $rider->id, $date->toDateString(),
                $input, $qris, $transfer, $gps, $reason,
            ], JSON_THROW_ON_ERROR));
            $keyHash = $idempotencyKey === null ? null : hash('sha256', $actor->id.':'.$idempotencyKey);
            if ($idempotencyKey !== null) {
                $priorRequest = DB::table('rider_closing_idempotency_keys')
                    ->where('key_hash', $keyHash)->first();
                if ($priorRequest) {
                    if ($priorRequest->request_hash !== $requestHash) {
                        throw new HttpResponseException(response()->json([
                            'code' => 'IDEMPOTENCY_CONFLICT',
                            'message' => 'Kunci kirim ini sudah dipakai dengan angka lain. Muat ulang Closing ya.',
                        ], 409));
                    }

                    return RiderClosing::query()->with('lines')->findOrFail($priorRequest->rider_closing_id);
                }
            }
            $pendingRefills = RefillRequest::query()->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())
                ->where('status', RefillStatus::DELIVERED->value)->orderBy('id')->get();
            foreach ($pendingRefills as $pending) {
                try {
                    $this->refills->postLedger($pending, $pending->rider_id ?? $actor->id);
                } catch (\Throwable $e) {
                    report($e);
                    throw new HttpResponseException(response()->json([
                        'code' => 'REFILL_LEDGER_PENDING',
                        'message' => 'Refill yang diterima masih diproses. Coba Closing lagi sebentar ya.',
                    ], 409));
                }
            }
            $closing = RiderClosing::query()->with('lines')->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())->lockForUpdate()->first();
            $settlement = Settlement::query()->where('cart_id', $cart->id)
                ->whereDate('operating_date', $date->toDateString())->lockForUpdate()->first();
            if ($closing && $closing->rider_id !== $rider->id) {
                throw new RuntimeException('Coffee Bike ini sudah Closing atas nama Rider lain. Minta Finance cek dulu ya.');
            }
            $financeActor = PermissionMatrix::can($actor, PanelModule::SETTLEMENTS, 'edit');
            if ($settlement?->reconciled_at && ! $financeActor) {
                throw new HttpResponseException(response()->json([
                    'code' => 'CLOSING_LOCKED',
                    'message' => 'Setoran sudah di-approve Finance. Koreksi Closing lewat Finance ya.',
                ], 409));
            }
            if ($financeActor && blank($reason)) {
                throw new RuntimeException('Alasan koreksi Finance wajib diisi.');
            }

            [$incoming, $carry] = $this->quantities($cart, $date);
            $officeSold = $this->officeSold($cart, $date);
            $ids = array_unique([...array_keys($incoming), ...array_keys($carry)]);
            sort($ids);
            $submitted = [];
            foreach ($input as $line) {
                $id = (int) $line['product_id'];
                if (isset($submitted[$id])) {
                    throw new RuntimeException('Produk Closing tidak boleh dobel.');
                }
                $submitted[$id] = $line;
            }
            $submittedIds = array_keys($submitted);
            sort($submittedIds);
            if ($ids === [] || $ids !== $submittedIds) {
                throw new RuntimeException('Daftar produk berubah. Muat ulang draft Closing ya.');
            }

            $this->ledger->lockAndProject(StockLedgerService::CART, $cart->id, $ids);
            $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $legacySold = DB::table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
                ->where('sales.cart_id', $cart->id)->whereDate('sales.operating_date', $date->toDateString())
                ->whereNull('sales.voided_at')->whereIn('sale_lines.product_id', $ids)
                ->groupBy('sale_lines.product_id')->selectRaw('sale_lines.product_id, SUM(sale_lines.qty) AS qty')
                ->pluck('qty', 'product_id');
            $before = $closing ? $this->snapshot($closing) : null;
            $oldLines = $closing?->lines->keyBy('product_id') ?? collect();
            $financeKeepsGps = $financeActor && $closing && ($gps['lat'] ?? null) === null
                && ($gps['lng'] ?? null) === null && ! ($gps['unavailable'] ?? false);
            if ($financeKeepsGps) {
                // A Finance cup/money correction has no new phone fix. Keep the Rider's evidence.
                $gps = ['lat' => $closing->gps_lat, 'lng' => $closing->gps_lng,
                    'unavailable' => $closing->gps_unavailable];
                $locationCheck = ['basis' => $closing->closing_basis,
                    'distance_m' => $closing->closing_distance_m,
                    'outside' => $closing->outside_closing_point];
            } else {
                $assignment = StaffAssignment::query()->where('user_id', $rider->id)
                    ->where('cart_id', $cart->id)->whereDate('operating_date', $date->toDateString())
                    ->where('source', 'hand_to_cart')->latest('id')->first();
                $point = $assignment ? app(RiderClosingPoint::class)->for($rider, $assignment) : [
                    'basis' => 'kitchen', 'lat' => null, 'lng' => null,
                    'radius_m' => (int) config('soul.absen_geofence_m', 10),
                ];
                $locationCheck = app(RiderClosingPoint::class)->check($point, $gps);
            }
            // Suspicion remains visible after a later correction with a closer GPS fix.
            $everOutside = (bool) ($closing?->outside_closing_point ?? false) || $locationCheck['outside'];
            $rows = [];
            $total = 0;
            $cogs = 0;
            foreach ($ids as $id) {
                $remaining = (int) $submitted[$id]['qty_remaining'];
                $wasted = (int) $submitted[$id]['qty_wasted'];
                $availableToRider = ($incoming[$id] ?? 0) + ($carry[$id] ?? 0) - ($officeSold[$id] ?? 0);
                if ($remaining + $wasted > $availableToRider) {
                    throw new RuntimeException("Sisa + rusak produk #{$id} melebihi stok setelah dijual kantor ({$availableToRider}).");
                }
                $sold = $availableToRider - $remaining - $wasted;
                $postedSale = $sold - (int) ($legacySold[$id] ?? 0);
                if ($postedSale < 0) {
                    throw new RuntimeException("Sisa produk #{$id} melebihi stok setelah penjualan lama. Muat ulang Closing ya.");
                }
                $price = $oldLines->get($id);
                $version = $price ? null : $products[$id]->priceVersionAt($date->isToday() ? now() : $date->copy()->endOfDay());
                if (! $price && ! $version) {
                    throw new RuntimeException("Harga produk #{$id} belum tersedia untuk hari Closing.");
                }
                $unitPrice = (int) ($price?->unit_price_minor ?? $version->sell_price_minor);
                $unitCost = (int) ($price?->unit_cost_minor ?? $version->cost_price_minor ?? 0);
                $subtotal = $sold * $unitPrice;
                $total += $subtotal;
                $cogs += $sold * $unitCost;
                $rows[$id] = [
                    'product_id' => $id,
                    'qty_in' => $incoming[$id] ?? 0,
                    'qty_carry' => $carry[$id] ?? 0,
                    'qty_remaining' => $remaining,
                    'qty_wasted' => $wasted,
                    'qty_sold' => $sold,
                    'qty_sale_posted' => $postedSale,
                    'qty_waste_posted' => $wasted,
                    'unit_price_minor' => $unitPrice,
                    'unit_cost_minor' => $unitCost,
                    'subtotal_minor' => $subtotal,
                ];
            }
            if ($qris + $transfer > $total) {
                throw new RuntimeException('QRIS + transfer melebihi nilai jual Closing.');
            }
            if ($closing && $closing->total_minor === $total && $closing->qris_minor === $qris
                && $closing->transfer_minor === $transfer && $closing->gps_unavailable === (bool) ($gps['unavailable'] ?? false)
                && $closing->closing_basis === $locationCheck['basis']
                && $closing->closing_distance_m === $locationCheck['distance_m']
                && $closing->outside_closing_point === $everOutside
                && ($closing->gps_lat === null ? ! isset($gps['lat']) : round((float) $closing->gps_lat, 7) === round((float) ($gps['lat'] ?? 0), 7))
                && ($closing->gps_lng === null ? ! isset($gps['lng']) : round((float) $closing->gps_lng, 7) === round((float) ($gps['lng'] ?? 0), 7))
                && collect($rows)->every(fn (array $row, int $id): bool => ($oldLines->get($id)?->qty_in ?? -1) === $row['qty_in']
                    && ($oldLines->get($id)?->qty_carry ?? -1) === $row['qty_carry']
                    && ($oldLines->get($id)?->qty_remaining ?? -1) === $row['qty_remaining']
                    && ($oldLines->get($id)?->qty_wasted ?? -1) === $row['qty_wasted'])) {
                $this->rememberKey($keyHash, $requestHash, $closing->id);

                return $closing;
            }

            $values = [
                'operating_date' => $date->toDateString(),
                'cart_id' => $cart->id,
                'rider_id' => $rider->id,
                'total_minor' => $total,
                'cogs_minor' => $cogs,
                'qris_minor' => $qris,
                'transfer_minor' => $transfer,
                'cash_minor' => $total - $qris - $transfer,
                'gps_lat' => $gps['lat'] ?? null,
                'gps_lng' => $gps['lng'] ?? null,
                'gps_unavailable' => (bool) ($gps['unavailable'] ?? false),
                'closing_basis' => $locationCheck['basis'],
                'closing_distance_m' => $locationCheck['distance_m'],
                'outside_closing_point' => $everOutside,
                'closed_at' => now(),
            ];
            if (! $closing) {
                $closing = RiderClosing::query()->create($values);
            } else {
                $closing->update($values);
            }

            foreach ($rows as $id => $row) {
                $old = $oldLines->get($id);
                foreach (['qty_sale_posted', 'qty_waste_posted'] as $field) {
                    if ($old && $old->$field > 0) {
                        $priorType = $field === 'qty_waste_posted' ? MovementType::WASTE_OUT : MovementType::SALE_OUT;
                        $prior = StockLedger::query()->where('location_type', StockLedgerService::CART)
                            ->where('location_id', $cart->id)->where('product_id', $id)
                            ->where('ref_type', 'rider_closing')->where('ref_id', $closing->id)
                            ->where('movement_type', $priorType)->orderByDesc('id')->first();
                        $resolvedCost = $prior ? DB::table('stock_cost_resolutions')
                            ->where('stock_ledger_id', $prior->id)->value('resolved_cost_minor') : null;
                        $this->ledger->post(StockLedgerService::CART, $cart->id, $id, MovementType::ADJUSTMENT,
                            (int) $old->$field, $actor->id, (int) $cart->kitchen_id,
                            $field === 'qty_waste_posted' ? 'rider_closing_waste_compensation' : 'rider_closing_sale_compensation',
                            $closing->id, costMinor: $prior ? (int) $prior->cost_minor + (int) $resolvedCost : null,
                            note: 'Kompensasi Closing sebelum revisi; standard_cost_minor='.(int) $old->unit_cost_minor);
                    }
                }
                $netCorrection = (int) ($old?->qty_sale_posted ?? 0)
                    + (int) ($old?->qty_waste_posted ?? 0)
                    - $row['qty_sale_posted'] - $row['qty_waste_posted'];
                $disposed = $this->wasDisposed($cart, $date, $id);
                if (($settlement?->reconciled_at || ($disposed && $old)) && $netCorrection !== 0) {
                    $this->ledger->post(StockLedgerService::CART, $cart->id, $id, MovementType::ADJUSTMENT,
                        -$netCorrection, $actor->id, (int) $cart->kitchen_id, 'rider_closing_after_disposition', $closing->id,
                        note: 'Koreksi Closing setelah cups fisik ditutup; stok Coffee Bike tidak berubah.');
                }
                if ($disposed && ! $old) {
                    $needed = $row['qty_sale_posted'] + $row['qty_waste_posted']
                        - $this->ledger->stockFor(StockLedgerService::CART, $cart->id, $id);
                    if ($needed > 0) {
                        $this->ledger->post(StockLedgerService::CART, $cart->id, $id, MovementType::ADJUSTMENT,
                            $needed, $actor->id, (int) $cart->kitchen_id, 'rider_closing_after_disposition', $closing->id,
                            note: 'Closing dicatat setelah Tutup Coffee Bike; saldo fisik diselaraskan.');
                    }
                }
                if ($row['qty_sale_posted'] > 0) {
                    $this->ledger->post(StockLedgerService::CART, $cart->id, $id, MovementType::SALE_OUT,
                        $row['qty_sale_posted'], $actor->id, (int) $cart->kitchen_id, 'rider_closing', $closing->id,
                        note: 'standard_cost_minor='.$row['unit_cost_minor']);
                }
                if ($row['qty_waste_posted'] > 0) {
                    $this->ledger->post(StockLedgerService::CART, $cart->id, $id, MovementType::WASTE_OUT,
                        $row['qty_waste_posted'], $actor->id, (int) $cart->kitchen_id, 'rider_closing', $closing->id,
                        note: 'standard_cost_minor='.$row['unit_cost_minor']);
                }
                $onHand = $this->ledger->stockFor(StockLedgerService::CART, $cart->id, $id);
                if ($disposed && ! $old && $onHand > 0) {
                    $this->ledger->post(StockLedgerService::CART, $cart->id, $id, MovementType::ADJUSTMENT,
                        -$onHand, $actor->id, (int) $cart->kitchen_id, 'rider_closing_after_disposition', $closing->id,
                        note: 'Closing dicatat setelah Tutup Coffee Bike; sisa tak terhitung ditandai.');
                    $onHand = 0;
                }
                if (! $settlement?->reconciled_at && ! $disposed && $onHand !== $row['qty_remaining']) {
                    throw new RuntimeException("Stok Coffee Bike produk #{$id} berubah. Muat ulang Closing ya.");
                }
                if ($onHand < 0) {
                    throw new RuntimeException("Stok Coffee Bike produk #{$id} tidak boleh negatif.");
                }
                if ($old) {
                    $old->update($row);
                } else {
                    $closing->lines()->create($row);
                }
            }

            $closing->load('lines');
            $this->flagEarlierCloseOutVariance($closing, $actor);
            $revisionId = DB::table('rider_closing_revisions')->insertGetId([
                'rider_closing_id' => $closing->id,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'snapshot_json' => json_encode($this->snapshot($closing), JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            $this->rememberKey($keyHash, $requestHash, $closing->id);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'actor_role' => $actor->role->value,
                'action' => $before ? 'rider_closing.corrected' : 'rider_closing.recorded',
                'subject_type' => RiderClosing::class, 'subject_id' => $closing->id,
                'before_json' => $before, 'after_json' => $this->snapshot($closing),
                'ip' => request()->ip(), 'device_id' => null,
            ]);
            if ($settlement?->reconciled_at) {
                $this->correctApprovedSettlement($settlement, $closing, $actor, $reason ?? '', $revisionId);
            } elseif ($settlement && $before) {
                $this->settlements->markExpectedStale($cart->id, $date, $actor, $total - (int) $before['total_minor'], true);
            }
            $recipientIds = User::query()->whereIn('role', [Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR])
                ->where('is_active', true)->pluck('id')->all();
            $this->events->publish(
                $before ? 'RiderClosingCorrected' : 'RiderClosingRecorded',
                'Closing Rider sudah masuk',
                "Closing Coffee Bike {$cart->code} sudah tercatat. Setoran bisa dicek ya.",
                ['role.FINANCE', 'role.MANAGEMENT', 'role.ADMINISTRATOR', 'user.'.$rider->id],
                notifyUserIds: $recipientIds,
                context: ['cart_id' => $cart->id, 'operating_date' => $date->toDateString(), 'source_id' => $closing->id],
            );
            if ($locationCheck['outside'] && ! $financeKeepsGps) {
                $fraudRecipients = User::query()->whereIn('role', [Role::ADMINISTRATOR, Role::FINANCE])
                    ->where('is_active', true)->pluck('id')->all();
                $this->events->publish(
                    'RiderClosingOutsidePoint',
                    'Closing di luar titik',
                    "Closing Coffee Bike {$cart->code} di luar titik atau GPS belum tersedia. Cek aktivitas Rider ya.",
                    ['role.ADMINISTRATOR', 'role.FINANCE'],
                    notifyUserIds: $fraudRecipients,
                    context: ['cart_id' => $cart->id, 'operating_date' => $date->toDateString(), 'source_id' => $closing->id],
                );
            }

            return $closing;
        });
    }

    private function wasDisposed(Cart $cart, Carbon $date, int $productId): bool
    {
        return StockLedger::query()->where('location_type', StockLedgerService::CART)
            ->where('location_id', $cart->id)->whereDate('created_at', $date->toDateString())
            ->where('product_id', $productId)
            ->whereIn('movement_type', [MovementType::RETURN_OUT->value, MovementType::WASTE_OUT->value, MovementType::ADJUSTMENT->value])
            ->whereIn('ref_type', ['cart_closeout_return', 'cart_closeout_reject', 'cart_closeout_count_adjustment', 'settlement_return', 'settlement_reject'])
            ->exists();
    }

    private function rememberKey(?string $keyHash, string $requestHash, int $closingId): void
    {
        if ($keyHash === null) {
            return;
        }
        DB::table('rider_closing_idempotency_keys')->insert([
            'key_hash' => $keyHash, 'request_hash' => $requestHash,
            'rider_closing_id' => $closingId, 'created_at' => now(),
        ]);
    }

    private function flagEarlierCloseOutVariance(RiderClosing $closing, User $actor): void
    {
        $counts = StockLedger::query()->where('location_type', StockLedgerService::CART)
            ->where('location_id', $closing->cart_id)
            ->whereDate('created_at', $closing->operating_date->toDateString())
            ->whereIn('ref_type', ['cart_closeout_return', 'cart_closeout_reject'])
            ->groupBy('product_id')->selectRaw('product_id, -SUM(qty_delta) AS qty')
            ->pluck('qty', 'product_id');
        $adjustedIds = StockLedger::query()->where('location_type', StockLedgerService::CART)
            ->where('location_id', $closing->cart_id)
            ->whereDate('created_at', $closing->operating_date->toDateString())
            ->where('ref_type', 'cart_closeout_count_adjustment')
            ->distinct()->pluck('product_id')->map(fn ($id): int => (int) $id)->all();
        $ids = array_unique([...$closing->lines->pluck('product_id')->all(), ...$counts->keys()->all(), ...$adjustedIds]);
        $flagged = false;
        foreach ($ids as $productId) {
            $line = $closing->lines->firstWhere('product_id', $productId);
            $remaining = (int) ($line?->qty_remaining ?? 0);
            $counted = (int) ($counts[$productId] ?? 0);
            if ((! $counts->has($productId) && ! in_array($productId, $adjustedIds, true)) || $counted === $remaining) {
                continue;
            }
            $already = DB::table('cart_closeout_variances')
                ->where('rider_closing_id', $closing->id)->where('product_id', $productId)
                ->where('qty_closing_remaining', $remaining)->where('qty_counted', $counted)->exists();
            if ($already) {
                continue;
            }
            DB::table('cart_closeout_variances')->insert([
                'cart_id' => $closing->cart_id, 'rider_closing_id' => $closing->id,
                'product_id' => $productId, 'actor_id' => $actor->id,
                'operating_date' => $closing->operating_date->toDateString(),
                'qty_closing_remaining' => $remaining,
                'qty_counted' => $counted, 'variance_qty' => $counted - $remaining,
                'created_at' => now(),
            ]);
            $flagged = true;
        }
        if ($flagged) {
            $recipients = PermissionMatrix::usersWith(PanelModule::SETTLEMENTS, 'edit');
            $this->events->publish('CartCloseOutVarianceFlagged', 'Selisih Tutup Coffee Bike',
                "Hitungan sisa Coffee Bike {$closing->cart?->code} beda dari Closing Rider. Cek di antrean setoran ya.",
                ['role.ADMINISTRATOR', 'role.FINANCE'], $recipients,
                context: ['cart_id' => $closing->cart_id, 'operating_date' => $closing->operating_date->toDateString(), 'source_id' => $closing->id]);
        }
    }

    private function snapshot(RiderClosing $closing): array
    {
        return [
            'id' => $closing->id, 'cart_id' => $closing->cart_id, 'rider_id' => $closing->rider_id,
            'operating_date' => $closing->operating_date->toDateString(),
            'total_minor' => $closing->total_minor, 'cogs_minor' => $closing->cogs_minor,
            'qris_minor' => $closing->qris_minor, 'transfer_minor' => $closing->transfer_minor,
            'cash_minor' => $closing->cash_minor, 'gps_lat' => $closing->gps_lat,
            'closing_basis' => $closing->closing_basis, 'closing_distance_m' => $closing->closing_distance_m,
            'outside_closing_point' => $closing->outside_closing_point,
            'gps_lng' => $closing->gps_lng, 'gps_unavailable' => $closing->gps_unavailable,
            'closed_at' => $closing->closed_at?->toIso8601String(),
            'lines' => $closing->lines->map(fn ($line): array => $line->only([
                'product_id', 'qty_in', 'qty_carry', 'qty_remaining', 'qty_wasted',
                'qty_sold', 'qty_sale_posted', 'qty_waste_posted', 'unit_price_minor',
                'unit_cost_minor', 'subtotal_minor',
            ]))->values()->all(),
        ];
    }

    private function correctApprovedSettlement(Settlement $settlement, RiderClosing $closing, User $actor, string $reason, int $revisionId): void
    {
        $revenueDelta = $closing->total_minor - $settlement->expected_total_minor;
        // Keep the chart -> tax-version lock order used by tax settings and settlement posting.
        Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
        $accounts = Account::query()->whereIn('code', ['4-1100', '2-1500', '6-1100'])->pluck('id', 'code');
        $line = static fn (string $code, int $debit, int $credit): array => [
            'account_id' => $accounts[$code], 'debit_minor' => $debit,
            'credit_minor' => $credit, 'cart_id' => $closing->cart_id,
        ];
        $header = [
            'entry_date' => today()->toDateString(), 'source_type' => 'rider_closing_revision',
            'source_id' => $revisionId, 'posted_by' => $actor->id,
            'description' => "Koreksi Closing Coffee Bike {$closing->cart?->code}: {$reason}",
        ];
        if ($revenueDelta !== 0) {
            $amount = abs($revenueDelta);
            $tax = app(TaxService::class);
            $date = $settlement->operating_date->toDateString();
            $before = $tax->splitPbjt((int) $settlement->expected_total_minor, $date);
            $after = $tax->splitPbjt((int) $closing->total_minor, $date);
            $revenueChange = $after['revenue'] - $before['revenue'];
            $taxChange = $after['tax'] - $before['tax'];
            $lines = $revenueDelta > 0 ? [$line('6-1100', $amount, 0)] : [$line('6-1100', 0, $amount)];
            if ($revenueChange !== 0) {
                $lines[] = $line('4-1100', max(0, -$revenueChange), max(0, $revenueChange));
            }
            if ($taxChange !== 0) {
                $lines[] = $line('2-1500', max(0, -$taxChange), max(0, $taxChange));
            }
            $this->journals->post([...$header, 'purpose' => 'SETTLEMENT_CORRECTION'], $lines);
        }
        $methodVariances = [
            'cash_variance_minor' => $settlement->cash_minor - $closing->cash_minor,
            'qris_variance_minor' => $settlement->qris_minor - $closing->qris_minor,
            'transfer_variance_minor' => $settlement->transfer_minor - $closing->transfer_minor,
        ];
        $methodFlagged = collect($methodVariances)->contains(fn (int $value): bool => $value !== 0);
        $settlement->update([
            'expected_total_minor' => $closing->total_minor,
            'expected_cash_minor' => $closing->cash_minor,
            'expected_qris_minor' => $closing->qris_minor,
            'expected_transfer_minor' => $closing->transfer_minor,
            'expected_cogs_minor' => $closing->cogs_minor,
            'variance_minor' => $settlement->declared_total_minor - $closing->total_minor,
            'variance_reason' => trim(($settlement->variance_reason ? $settlement->variance_reason.' — ' : '').$reason),
            'expected_stale' => false,
            'stale_delta_minor' => 0,
            'method_variance_flagged' => $methodFlagged,
            ...$methodVariances,
        ]);
        if ($methodFlagged) {
            AuditLog::recordAction($actor, 'settlement.method_variance_flagged', $settlement, null, [
                'closing_revision_id' => $revisionId, ...$methodVariances,
            ]);
            $recipientIds = PermissionMatrix::usersWith(PanelModule::SETTLEMENTS, 'edit');
            $this->events->publish(
                type: 'SettlementMethodVarianceFlagged', title: 'Selisih metode setoran perlu dicek',
                body: sprintf('Setoran Coffee Bike %s berubah setelah koreksi Closing. Cek rincian metode ya.', $closing->cart?->code),
                channels: array_map(fn (int $id): string => "user.{$id}", $recipientIds),
                notifyUserIds: $recipientIds, status: $settlement->status, includeActorPush: true,
                context: ['cart_id' => $closing->cart_id, 'operating_date' => $closing->operating_date->toDateString(),
                    'source_id' => $settlement->id],
            );
        }
    }
}
