<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AppNotification;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Media;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RefillRequest;
use App\Models\RefillRequestLine;
use App\Models\RiderClosing;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\StaffLocationPing;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\PendingStockCostService;
use App\Services\RefillRequestStateMachine;
use App\Services\Reporting\SalesActivityService;
use App\Services\RiderCommissionService;
use App\Services\StockLedgerService;
use App\Services\TaxService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RiderClosingTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    private User $barista;

    private User $finance;

    private Cart $cart;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['outbox.publish_mode' => 'queue']);
        $this->seed(AccountSeeder::class);
        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);
        $this->cart = Cart::create(['code' => 'T041', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $location = Location::create(['name' => 'Area Uji', 'lat' => -6.18, 'lng' => 106.88]);
        $this->product = Product::create([
            'code' => 'T041-P', 'name' => 'Kopi Uji', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
        ProductPriceVersion::create([
            'product_id' => $this->product->id, 'cost_price_minor' => 8000,
            'sell_price_minor' => 20000, 'effective_from' => now()->subDays(2),
        ]);
        $this->rider = User::factory()->role(Role::STAFF)->create();
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $kitchen->id]);
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        StaffAssignment::create([
            'source' => 'hand_to_cart', 'user_id' => $this->rider->id,
            'cart_id' => $this->cart->id, 'location_id' => $location->id,
            'operating_date' => today()->toDateString(), 'assigned_by' => $this->barista->id,
            'kitchen_id' => $kitchen->id,
        ]);
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista);
        $attendance->openStaffWindow($this->barista);
        $attendance->clockIn($this->rider);
    }

    private function issue(int $qty, MovementType $type, ?Carbon $at = null): void
    {
        app(StockLedgerService::class)->post(
            StockLedgerService::CART, $this->cart->id, $this->product->id, $type,
            $qty, $this->barista->id, $this->cart->kitchen_id,
            occurredAt: $at,
        );
    }

    private function body(int $remaining = 3, int $wasted = 2): array
    {
        return [
            'lines' => [['product_id' => $this->product->id, 'qty_remaining' => $remaining, 'qty_wasted' => $wasted]],
            'qris' => 100000, 'transfer' => 20000,
            'gps_lat' => -6.18, 'gps_lng' => 106.88,
        ];
    }

    private function closingKey(): string
    {
        return (string) Str::uuid();
    }

    public function test_finance_role_matrix_for_closing_corrections(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', $this->body(3, 0))->assertCreated();
        $closing = RiderClosing::query()->sole();

        foreach ([Role::BARISTA, Role::STAFF, Role::RIDER, Role::CONTENT_CREATOR] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
                ->postJson("/api/v1/closings/{$closing->id}/corrections", [
                    ...$this->body(3, 0), 'reason' => 'Uji hak akses',
                ])->assertForbidden();
        }

        foreach ([Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR] as $index => $role) {
            $actor = $role === Role::FINANCE ? $this->finance : User::factory()->role($role)->create();
            $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
                ->postJson("/api/v1/closings/{$closing->id}/corrections", [
                    ...$this->body(3, 0), 'qris' => 90000 + $index * 10000,
                    'reason' => 'Uji koreksi',
                ])->assertOk();
        }

        $this->assertDatabaseHas('audit_log', [
            'action' => 'rider_closing.corrected', 'actor_role' => Role::ADMINISTRATOR->value,
        ]);
    }

    public function test_correction_after_cost_revaluation_restores_sale_and_waste_at_resolved_cost(): void
    {
        ProductPriceVersion::query()->where('product_id', $this->product->id)
            ->update(['cost_price_minor' => 0]);
        $ledger = app(StockLedgerService::class);
        $ledger->post(StockLedgerService::KITCHEN, $this->cart->kitchen_id, $this->product->id,
            MovementType::PRODUCTION_IN, 5, $this->barista->id, $this->cart->kitchen_id,
            'pending_closing_production', 1);
        $ledger->transfer(StockLedgerService::KITCHEN, $this->cart->kitchen_id,
            StockLedgerService::CART, $this->cart->id, $this->product->id,
            MovementType::ALLOCATION_OUT, MovementType::ALLOCATION_IN, 5,
            $this->barista->id, $this->cart->kitchen_id, 'pending_closing_transfer', 1);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(2, 1), 'qris' => 0, 'transfer' => 0])
            ->assertCreated();
        ProductPriceVersion::create([
            'product_id' => $this->product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 20000, 'effective_from' => now(),
        ]);
        $this->assertGreaterThan(0, app(PendingStockCostService::class)
            ->resolveProduct($this->product, $this->finance));

        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 0, 'transfer' => 0])
            ->assertOk();

        $compensations = StockLedger::query()->whereIn('ref_type', [
            'rider_closing_sale_compensation', 'rider_closing_waste_compensation',
        ])->get()->pluck('cost_minor', 'ref_type')->all();
        $this->assertSame(2000, $compensations['rider_closing_sale_compensation']);
        $this->assertSame(1000, $compensations['rider_closing_waste_compensation']);
        $this->assertSame([3, 3000], array_slice(app(StockLedgerService::class)->valuation(
            StockLedgerService::CART, $this->cart->id, $this->product->id), 0, 2));
        $book = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', '1-1600')->selectRaw('SUM(l.debit_minor) AS debit, SUM(l.credit_minor) AS credit')
            ->first();
        $this->assertSame(3000, (int) $book->debit - (int) $book->credit);
        $this->assertSame((int) DB::table('journal_lines')->sum('debit_minor'),
            (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    public function test_closing_uses_handover_refill_and_carry_then_posts_aggregate_ledger_once(): void
    {
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $administrator = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->issue(2, MovementType::ALLOCATION_IN, now()->subDay());
        $this->issue(20, MovementType::ALLOCATION_IN);
        $this->issue(5, MovementType::REFILL_IN);

        $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/settlements/draft/'.$this->cart->id)
            ->assertStatus(409)->assertJsonPath('code', 'CLOSING_REQUIRED');
        $draft = $this->actingAs($this->rider, 'sanctum')
            ->getJson('/api/v1/me/closing/draft')->assertOk();
        $draft->assertJsonPath('data.lines.0.qty_in', 25)
            ->assertJsonPath('data.lines.0.qty_carry', 2)
            ->assertJsonPath('data.carry_warning', true);

        $key = $this->closingKey();
        $response = $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/me/closing', $this->body())->assertCreated();
        $response->assertJsonPath('data.lines.0.qty_sold', 22)
            ->assertJsonPath('data.total_minor', 440000)
            ->assertJsonPath('data.cash_minor', 320000);
        foreach ([$this->finance, $management, $administrator] as $recipient) {
            $this->assertTrue(AppNotification::query()->where('type', 'RiderClosingRecorded')
                ->where('user_id', $recipient->id)->exists());
        }
        foreach ([$this->rider, $this->barista] as $nonRecipient) {
            $this->assertFalse(AppNotification::query()->where('type', 'RiderClosingRecorded')
                ->where('user_id', $nonRecipient->id)->exists());
        }
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/me/closing', $this->body())->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('rider_closings', 1);
        $this->assertDatabaseCount('rider_closing_revisions', 1);
        $this->assertSame(-22, (int) StockLedger::query()->where('ref_type', 'rider_closing')
            ->where('movement_type', MovementType::SALE_OUT->value)->sum('qty_delta'));
        $this->assertSame(-2, (int) StockLedger::query()->where('ref_type', 'rider_closing')
            ->where('movement_type', MovementType::WASTE_OUT->value)->sum('qty_delta'));
        $this->assertSame(3, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));

        $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/settlements/draft/'.$this->cart->id)->assertOk()
            ->assertJsonPath('data.expected_total_minor', 440000)
            ->assertJsonPath('data.expected_cash_minor', 320000)
            ->assertJsonPath('data.lines.0.qty_sold', 22)
            ->assertJsonPath('data.lines.0.qty_closing_remaining', 3);
        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/settlements/queue')
            ->assertOk()->assertJsonPath('data.0.closing_status', 'Sudah closing')
            ->assertJsonPath('data.0.carry_warning', true);
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 320000,
                'qris' => 100000, 'transfer' => 20000,
            ])->assertCreated();
        $settlementLine = Settlement::query()->firstOrFail()->lines()->firstOrFail();
        $this->assertSame(2, $settlementLine->qty_wasted);
        $this->assertSame(0, $settlementLine->variance_qty);
    }

    public function test_invalid_quantity_roles_gate_and_correction_lock(): void
    {
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        app(TaxService::class)->saveSetting($management, [
            'tax_type' => 'PBJT', 'is_active' => true, 'rate_bps' => 1000,
            'price_mode' => 'INCLUDED', 'scheme' => null,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'legal_note' => null, 'monthly_threshold_minor' => null, 'reason' => 'Tarif uji baru',
        ]);
        app(RiderCommissionService::class)->save([$this->rider->id], today()->toDateString(), [
            ['min_cups' => 5, 'amount_per_cup_minor' => 1000],
            ['min_cups' => 6, 'amount_per_cup_minor' => 1500],
        ], $this->finance, $this->closingKey());
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/me/closing/draft')->assertForbidden();
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', $this->body())->assertForbidden();
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', $this->body(9, 2))->assertUnprocessable();
        $this->assertDatabaseCount('rider_closings', 0);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 2), 'qris' => 20000, 'transfer' => 10000])->assertCreated();
        $this->assertSame(5000, app(RiderCommissionService::class)->day($this->rider->id, today()->toDateString())['commission_minor']);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(2, 2), 'qris' => 20000, 'transfer' => 10000])->assertOk()
            ->assertJsonPath('data.lines.0.qty_sold', 6)
            ->assertJsonPath('data.commission.commission_minor', 9000);
        $this->assertSame(6, RiderClosing::query()->firstOrFail()->lines()->firstOrFail()->qty_sold);
        $this->assertDatabaseCount('rider_closing_revisions', 2);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/sales', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertStatus(409)->assertJsonPath('code', 'CLOSING_DONE');

        $closing = RiderClosing::query()->firstOrFail();
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => $closing->cash_minor,
                'qris' => $closing->qris_minor, 'transfer' => $closing->transfer_minor,
            ])->assertCreated();
        $settlementId = (int) Settlement::query()->firstOrFail()->id;
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson("/api/v1/settlements/{$settlementId}/approve", [
                'lines' => [['product_id' => $this->product->id, 'qty_returned' => 2, 'qty_rejected' => 0]],
            ])->assertOk();
        $this->assertDatabaseHas('rider_daily_commission_locks', [
            'rider_id' => $this->rider->id, 'commission_minor' => 9000, 'cups_sold' => 6,
        ]);
        app(RiderCommissionService::class)->save([$this->rider->id], today()->toDateString(), [
            ['min_cups' => 5, 'amount_per_cup_minor' => 3000],
        ], $this->finance, $this->closingKey());
        $this->assertSame(9000, app(RiderCommissionService::class)->day($this->rider->id, today()->toDateString())['commission_minor']);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(1, 2), 'qris' => 20000, 'transfer' => 10000])
            ->assertStatus(409)->assertJsonPath('code', 'CLOSING_LOCKED');
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson("/api/v1/closings/{$closing->id}/corrections", [...$this->body(1, 2), 'reason' => 'Tidak boleh'])
            ->assertForbidden();
        $this->travel(1)->days();
        $this->issue(5, MovementType::ALLOCATION_IN);
        $this->actingAs($management, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson("/api/v1/closings/{$closing->id}/corrections", [...$this->body(1, 2), 'qris' => 20000, 'transfer' => 10000, 'reason' => 'Hitung ulang'])
            ->assertOk()->assertJsonPath('data.total_minor', 140000);
        $this->assertDatabaseHas('settlements', ['id' => $settlementId, 'expected_total_minor' => 140000]);
        $this->assertSame(9000, app(RiderCommissionService::class)->day($this->rider->id, $closing->operating_date->toDateString())['commission_minor']);
        $this->assertSame(5, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        $this->assertTrue(JournalEntry::query()->where('purpose', 'SETTLEMENT_CORRECTION')->exists());
        $originalTax = JournalEntry::query()->where('purpose', 'SETTLEMENT')->firstOrFail()->lines()
            ->whereHas('account', fn ($query) => $query->where('code', '2-1500'))->firstOrFail();
        $correctionTax = JournalEntry::query()->where('purpose', 'SETTLEMENT_CORRECTION')->firstOrFail()->lines()
            ->whereHas('account', fn ($query) => $query->where('code', '2-1500'))->firstOrFail();
        $this->assertSame(10909, (int) $originalTax->credit_minor);
        $this->assertSame(1818, (int) $correctionTax->credit_minor);
        foreach (JournalEntry::query()->whereIn('purpose', ['SETTLEMENT', 'SETTLEMENT_COGS', 'SETTLEMENT_CORRECTION', 'SETTLEMENT_COGS_CORRECTION'])->with('lines')->get() as $journal) {
            $this->assertSame((int) $journal->lines->sum('debit_minor'), (int) $journal->lines->sum('credit_minor'));
        }
    }

    public function test_approved_closing_method_correction_updates_flag_and_notifies_management(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0])
            ->assertCreated();
        $closing = RiderClosing::query()->sole();
        $id = $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => $closing->cash_minor,
                'qris' => $closing->qris_minor, 'transfer' => 0,
            ])->assertCreated()->assertJsonPath('data.method_variance_flagged', false)->json('data.id');
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson("/api/v1/settlements/{$id}/approve", [
                'lines' => [['product_id' => $this->product->id, 'qty_returned' => 3, 'qty_rejected' => 0]],
            ])->assertOk();
        $this->actingAs($management, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson("/api/v1/closings/{$closing->id}/corrections", [
                ...$this->body(3, 0), 'qris' => 30000, 'transfer' => 0,
                'reason' => 'Metode QRIS dikoreksi',
            ])->assertOk();
        $settlement = Settlement::query()->findOrFail($id);
        $this->assertSame(0, $settlement->variance_minor);
        $this->assertTrue($settlement->method_variance_flagged);
        $this->assertSame(10000, $settlement->cash_variance_minor);
        $this->assertSame(-10000, $settlement->qris_variance_minor);
        $event = OutboxEvent::query()->where('name', 'SettlementMethodVarianceFlagged')->sole();
        $this->assertSame(1, AppNotification::query()->where('event_id', $event->event_id)
            ->where('user_id', $management->id)->count());
    }

    public function test_day_gate_and_legacy_sale_are_respected(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->travel(1)->days();
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', $this->body(3, 0))
            ->assertUnprocessable()->assertJsonPath('code', 'DAY_NOT_OPEN');
        $this->travelBack();

        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/sales', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
            ])->assertCreated();
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 1), 'qris' => 20000, 'transfer' => 0])
            ->assertCreated()->assertJsonPath('data.lines.0.qty_sold', 6);
        $this->assertSame(-4, (int) StockLedger::query()->where('ref_type', 'rider_closing')
            ->where('movement_type', MovementType::SALE_OUT->value)->sum('qty_delta'));
        $this->assertSame(3, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
    }

    public function test_production_closeout_prefills_and_flags_a_difference_without_blocking(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0])
            ->assertCreated();
        $this->actingAs($this->barista, 'sanctum')
            ->getJson('/api/v1/showcase/close-out/draft/'.$this->cart->id)->assertOk()
            ->assertJsonPath('data.lines.0.qty_to_return', 3);
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/showcase/close-out', [
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 2]],
            ])->assertOk();
        $this->assertDatabaseHas('cart_closeout_variances', [
            'cart_id' => $this->cart->id, 'product_id' => $this->product->id,
            'qty_closing_remaining' => 3, 'qty_counted' => 2, 'variance_qty' => -1,
        ]);
        $this->assertTrue(OutboxEvent::query()->where('name', 'CartCloseOutVarianceFlagged')->exists());
        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/settlements/queue')
            ->assertOk()->assertJsonPath('data.0.closeout_variance_count', 1);
    }

    public function test_dashboard_uses_closing_and_activity_signals(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0])
            ->assertCreated();
        StaffLocationPing::create([
            'user_id' => $this->rider->id, 'operating_date' => today()->toDateString(),
            'recorded_at' => now(), 'captured_at' => now(),
            'lat' => -6.18, 'lng' => 106.88, 'accuracy_m' => 10,
            'source' => 'background', 'cart_id' => $this->cart->id,
            'location_id' => StaffAssignment::query()->firstOrFail()->location_id,
        ]);
        $report = app(SalesActivityService::class);
        $this->assertSame(7, $report->dayTotals()['cups']);
        $this->assertSame(140000, $report->dayTotals()['revenue']);
        $this->assertSame(7, $report->perCart()[0]['cups']);
        $this->assertSame('activity_signals', $report->areaHours()['basis']);
        $this->assertSame(1, $report->areaHours()['peak']['cups']);
        $this->assertTrue(OutboxEvent::query()->where('name', 'RiderClosingRecorded')->exists());
    }

    public function test_physical_surplus_is_adjusted_and_flagged_without_blocking(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0])->assertCreated();
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/showcase/close-out', [
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 4]],
            ])->assertOk();
        $this->assertDatabaseHas('cart_closeout_variances', [
            'product_id' => $this->product->id, 'qty_closing_remaining' => 3,
            'qty_counted' => 4, 'variance_qty' => 1,
        ]);
        $this->assertSame(0, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        $this->assertSame(1, (int) StockLedger::query()->where('ref_type', 'cart_closeout_count_adjustment')->sum('qty_delta'));
    }

    public function test_closeout_flags_unlisted_product_and_missing_expected_cups(): void
    {
        $extra = Product::create([
            'code' => 'T041-EXTRA', 'name' => 'Cup Tak Terduga', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 2, 'is_active' => true,
        ]);
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0])->assertCreated();
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/showcase/close-out', [
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $extra->id, 'qty' => 1]],
            ])->assertOk();
        $this->assertDatabaseHas('cart_closeout_variances', [
            'product_id' => $this->product->id, 'qty_closing_remaining' => 3,
            'qty_counted' => 0, 'variance_qty' => -3,
        ]);
        $this->assertDatabaseHas('cart_closeout_variances', [
            'product_id' => $extra->id, 'qty_closing_remaining' => 0,
            'qty_counted' => 1, 'variance_qty' => 1,
        ]);
        $this->assertSame(0, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        $this->assertSame(0, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $extra->id));
    }

    public function test_zero_physical_count_is_accepted_and_flagged_after_closing(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0])->assertCreated();
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/showcase/close-out', [
                'cart_id' => $this->cart->id, 'returned' => [], 'rejected' => [],
            ])->assertOk();
        $this->assertDatabaseHas('cart_closeout_variances', [
            'product_id' => $this->product->id, 'qty_counted' => 0, 'variance_qty' => -3,
        ]);
        $this->assertSame(0, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(4, 0), 'qris' => 10000, 'transfer' => 0])->assertOk();
        $this->assertSame(0, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        $this->assertDatabaseHas('cart_closeout_variances', [
            'product_id' => $this->product->id, 'qty_closing_remaining' => 4,
            'qty_counted' => 0, 'variance_qty' => -4,
        ]);
    }

    public function test_correction_after_closeout_keeps_physical_stock_and_stale_settlement_cannot_approve(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0])->assertCreated();
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/showcase/close-out', [
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 3]],
            ])->assertOk();
        $closing = RiderClosing::query()->firstOrFail();
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => $closing->cash_minor,
                'qris' => $closing->qris_minor, 'transfer' => 0,
            ])->assertCreated();
        $this->assertSame(0, Settlement::query()->firstOrFail()->lines()->firstOrFail()->variance_qty);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(4, 0), 'qris' => 20000, 'transfer' => 0])->assertOk();
        $this->assertSame(0, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        $this->assertDatabaseHas('cart_closeout_variances', ['qty_closing_remaining' => 4, 'qty_counted' => 3, 'variance_qty' => -1]);
        $settlement = Settlement::query()->firstOrFail();
        $this->assertTrue($settlement->expected_stale);
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson("/api/v1/settlements/{$settlement->id}/approve", ['lines' => []])->assertUnprocessable();
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 100000,
                'qris' => 20000, 'transfer' => 0,
            ])->assertCreated();
        $this->assertFalse($settlement->fresh()->expected_stale);
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson("/api/v1/settlements/{$settlement->id}/approve", ['lines' => []])->assertOk();
    }

    public function test_approved_settlement_closing_correction_keeps_inventory_book_aligned_with_physical_change(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0])->assertCreated();
        $closing = RiderClosing::query()->firstOrFail();
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => $closing->cash_minor,
                'qris' => $closing->qris_minor, 'transfer' => 0,
            ])->assertCreated();
        $settlement = Settlement::query()->firstOrFail();
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson("/api/v1/settlements/{$settlement->id}/approve", [
                'lines' => [['product_id' => $this->product->id, 'qty_returned' => 3, 'qty_rejected' => 0]],
            ])->assertOk();
        $stockAccountId = Account::query()->where('code', '1-1600')->firstOrFail()->id;
        $book = fn (): int => (int) DB::table('journal_lines')->where('account_id', $stockAccountId)->sum('debit_minor')
            - (int) DB::table('journal_lines')->where('account_id', $stockAccountId)->sum('credit_minor');
        $beforeBook = $book();
        $beforeStock = app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id);

        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson("/api/v1/closings/{$closing->id}/corrections", [
                ...$this->body(2, 0), 'qris' => 20000, 'transfer' => 0, 'reason' => 'Hitung ulang cups',
            ])->assertOk();

        $this->assertSame($beforeStock, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        $this->assertSame($beforeBook, $book());
        $disposition = StockLedger::query()->where('ref_type', 'rider_closing_after_disposition')->firstOrFail();
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'stock_ledger', 'source_id' => $disposition->id, 'purpose' => 'STOCK_LOSS',
        ]);
    }

    public function test_closeout_before_closing_is_reconciled_and_flagged(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/showcase/close-out', [
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 3]],
            ])->assertOk();
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(2, 0), 'qris' => 20000, 'transfer' => 0])->assertCreated();
        $this->assertDatabaseHas('cart_closeout_variances', [
            'qty_closing_remaining' => 2, 'qty_counted' => 3, 'variance_qty' => 1,
        ]);
        $this->assertSame(0, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
    }

    public function test_same_closing_payload_with_new_key_is_noop_and_void_after_closing_is_refused(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $saleId = $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/sales', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertCreated()->json('data.id');
        $body = [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0];
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', $body)->assertCreated();
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', $body)->assertOk();
        $this->assertDatabaseCount('rider_closing_revisions', 1);
        $this->assertSame(1, OutboxEvent::query()->where('name', 'RiderClosingRecorded')->count());
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'Salah'])->assertUnprocessable();
    }

    public function test_delivered_refill_ledger_finishes_before_closing_can_record(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $photo = Media::create([
            'kind' => 'evidence', 'path' => 'test/refill.jpg', 'mime' => 'image/jpeg',
            'bytes' => 100, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $this->rider->id,
        ]);
        $runner = User::factory()->role(Role::RIDER)->create();
        $refill = RefillRequest::create([
            'uuid' => (string) Str::uuid(), 'code' => 'REF-T041',
            'operating_date' => today()->toDateString(), 'cart_id' => $this->cart->id,
            'staff_id' => $this->rider->id, 'kitchen_id' => $this->cart->kitchen_id,
            'evidence_photo_id' => $photo->id, 'handover_photo_id' => $photo->id,
            'rider_id' => $runner->id, 'status' => RefillStatus::DELIVERED,
        ]);
        RefillRequestLine::create([
            'refill_request_id' => $refill->id, 'product_id' => $this->product->id,
            'qty_requested' => 5, 'qty_approved' => 5, 'qty_prepared' => 5,
            'qty_received' => 5, 'unit_cost_minor' => 8000, 'line_cost_minor' => 40000,
        ]);
        $failedPoster = \Mockery::mock(RefillRequestStateMachine::class);
        $attempt = 0;
        $failedPoster->shouldReceive('postLedger')->twice()->andReturnUsing(function (RefillRequest $pending, int $actorId) use (&$attempt): void {
            if (++$attempt === 1) {
                throw new \RuntimeException('Ledger belum tersedia');
            }
            app()->build(RefillRequestStateMachine::class)->postLedger($pending, $actorId);
        });
        app()->instance(RefillRequestStateMachine::class, $failedPoster);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0])
            ->assertStatus(409)->assertJsonPath('code', 'REFILL_LEDGER_PENDING');
        $this->assertSame(RefillStatus::DELIVERED, $refill->fresh()->status);
        app(StockLedgerService::class)->post(
            StockLedgerService::KITCHEN, $this->cart->kitchen_id, $this->product->id,
            MovementType::PRODUCTION_IN, 5, $this->barista->id, $this->cart->kitchen_id,
        );
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->closingKey())
            ->postJson('/api/v1/me/closing', [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0])
            ->assertCreated()->assertJsonPath('data.lines.0.qty_sold', 12);
        $this->assertSame(RefillStatus::CLOSED, $refill->fresh()->status);
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/closing/draft')
            ->assertOk()->assertJsonPath('data.lines.0.qty_in', 15);
    }

    public function test_old_key_replay_after_noop_and_correction_does_not_revert_closing(): void
    {
        $this->issue(10, MovementType::ALLOCATION_IN);
        $first = [...$this->body(3, 0), 'qris' => 20000, 'transfer' => 0];
        $second = [...$this->body(2, 0), 'qris' => 20000, 'transfer' => 0];
        $key1 = $this->closingKey();
        $key2 = $this->closingKey();
        $key3 = $this->closingKey();
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $key1)
            ->postJson('/api/v1/me/closing', $first)->assertCreated();
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $key2)
            ->postJson('/api/v1/me/closing', $first)->assertOk();
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $key3)
            ->postJson('/api/v1/me/closing', $second)->assertOk();
        Cache::forget('idem:'.$this->rider->id.':'.hash('sha256', $key2));
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $key2)
            ->postJson('/api/v1/me/closing', $first)->assertOk()
            ->assertJsonPath('data.lines.0.qty_sold', 8);
        $this->assertDatabaseCount('rider_closing_revisions', 2);
        $this->assertDatabaseCount('rider_closing_idempotency_keys', 3);
        $this->assertSame(-15, (int) StockLedger::query()->where('ref_type', 'rider_closing')
            ->where('movement_type', MovementType::SALE_OUT->value)->sum('qty_delta'));
        $this->assertSame(2, OutboxEvent::query()->whereIn('name', ['RiderClosingRecorded', 'RiderClosingCorrected'])->count());
    }
}
