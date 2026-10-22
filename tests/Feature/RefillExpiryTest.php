<?php

namespace Tests\Feature;

use App\Enums\PanelModule;
use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Filament\Resources\RefillRequests\Pages\ListRefillRequests;
use App\Filament\Resources\RefillRequests\RefillRequestResource;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\Media;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RefillRequest;
use App\Models\RefillRequestLine;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\StaffAssignment;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\AttendanceService;
use App\Services\RefillExpiryDecisionService;
use App\Services\RefillRequestStateMachine;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RefillExpiryTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Product $product;

    private User $staff;

    private User $finance;

    private User $barista;

    protected function setUp(): void
    {
        parent::setUp();
        config(['soul.closing_started_on' => today()->addDay()->toDateString()]);
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji', 'open_at' => '00:00:00',
            'close_at' => '23:59:59', 'is_active' => true,
        ]);
        $this->product = Product::create([
            'code' => 'T035-COFFEE', 'name' => 'Kopi Uji', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
        ProductPriceVersion::create([
            'product_id' => $this->product->id, 'cost_price_minor' => 8000,
            'sell_price_minor' => 20000, 'effective_from' => now()->subDays(2),
        ]);
        $this->staff = User::factory()->role(Role::STAFF)->create();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function refill(RefillStatus $status, string $date = '2026-10-17', int $prepared = 0): RefillRequest
    {
        $cart = Cart::create([
            'code' => str_pad((string) (Cart::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'active', 'kitchen_id' => $this->kitchen->id,
        ]);
        $media = Media::create([
            'kind' => 'evidence', 'path' => 'evidence/'.Str::uuid().'.jpg',
            'mime' => 'image/jpeg', 'bytes' => 1000,
            'sha256' => hash('sha256', (string) Str::uuid()),
            'exif_taken_at' => now(), 'uploaded_by' => $this->staff->id,
        ]);
        $refill = RefillRequest::create([
            'uuid' => (string) Str::uuid(), 'code' => 'REF-TEST-'.Str::uuid(),
            'operating_date' => $date, 'cart_id' => $cart->id,
            'staff_id' => $this->staff->id, 'kitchen_id' => $this->kitchen->id,
            'evidence_photo_id' => $media->id, 'status' => $status, 'version' => 0,
            'submitted_at' => now(),
        ]);
        RefillRequestLine::create([
            'refill_request_id' => $refill->id, 'product_id' => $this->product->id,
            'qty_requested' => 5, 'qty_approved' => $status === RefillStatus::SUBMITTED ? null : 5,
            'qty_prepared' => $prepared > 0 ? $prepared : null,
            'unit_cost_minor' => 8000, 'line_cost_minor' => 40000,
        ]);

        return $refill;
    }

    public function test_every_open_status_expires_without_stock_change_and_notifies_all_parties(): void
    {
        $requests = [];
        foreach ([RefillStatus::SUBMITTED, RefillStatus::APPROVED, RefillStatus::PREPARING,
            RefillStatus::READY_TO_PICK, RefillStatus::PICKED_UP] as $status) {
            $requests[] = $this->refill($status);
        }

        Artisan::call('refills:expire-stale');
        $this->assertStringContainsString('Diproses: 5', Artisan::output());
        foreach ($requests as $request) {
            $this->assertSame(RefillStatus::EXPIRED, $request->fresh()->status);
            $this->assertNull($request->fresh()->active_cart_id);
            $this->assertDatabaseHas('refill_status_history', [
                'refill_request_id' => $request->id, 'from_status' => $request->status->value,
                'to_status' => 'EXPIRED', 'actor_id' => null, 'actor_role' => 'SYSTEM',
            ]);
        }
        $this->assertSame(0, StockLedger::count());
        $this->assertSame(5, OutboxEvent::where('name', 'RefillRequestExpired')->count());
        $events = OutboxEvent::where('name', 'RefillRequestExpired')->get();
        $payload = $events->first(fn (OutboxEvent $event): bool => $event->payload_json['refill_request_id'] === $requests[0]->id)->payload_json;
        $this->assertContains('user.'.$this->finance->id, $payload['channels']);
        $this->assertContains('user.'.$this->staff->id, $payload['channels']);
        $this->assertNotContains('role.RIDER', $payload['channels']);
        $this->assertContains('kitchen.'.$this->kitchen->id, $payload['channels']);
        $readyPayload = $events->first(fn (OutboxEvent $event): bool => $event->payload_json['refill_request_id'] === $requests[3]->id)->payload_json;
        $this->assertContains('role.RIDER', $readyPayload['channels']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->finance->id, 'type' => 'RefillRequestExpired',
        ]);
    }

    public function test_today_is_unchanged_until_next_operating_date_and_command_is_idempotent(): void
    {
        $refill = $this->refill(RefillStatus::PICKED_UP, '2026-10-18', 5);
        Artisan::call('refills:expire-stale');
        $this->assertStringContainsString('Diproses: 0', Artisan::output());
        $this->assertSame(RefillStatus::PICKED_UP, $refill->fresh()->status);

        Carbon::setTestNow('2026-10-19 00:01:00');
        Artisan::call('refills:expire-stale', ['--dry-run' => true]);
        $this->assertStringContainsString($refill->code.' | PICKED_UP | 1 hari', Artisan::output());
        $this->assertSame(RefillStatus::PICKED_UP, $refill->fresh()->status);
        Artisan::call('refills:expire-stale');
        $this->assertStringContainsString('Diproses: 1', Artisan::output());
        Artisan::call('refills:expire-stale');
        $this->assertStringContainsString('Diproses: 0', Artisan::output());
        $this->assertSame(1, OutboxEvent::where('name', 'RefillRequestExpired')->count());
    }

    public function test_opening_the_daily_attendance_window_expires_stale_refills(): void
    {
        $old = $this->refill(RefillStatus::READY_TO_PICK, prepared: 5);
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista);
        $attendance->openStaffWindow($this->barista);

        $this->assertSame(RefillStatus::EXPIRED, $old->fresh()->status);
        $this->assertSame(1, OutboxEvent::where('name', 'RefillRequestExpired')->count());
    }

    public function test_lazy_expiry_on_read_and_new_submission_releases_r2(): void
    {
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista);
        $attendance->openStaffWindow($this->barista);
        $attendance->clockIn($this->staff);

        $old = $this->refill(RefillStatus::APPROVED);
        StaffAssignment::create([
            'source' => 'hand_to_cart',
            'user_id' => $this->staff->id, 'cart_id' => $old->cart_id,
            'kitchen_id' => $this->kitchen->id, 'operating_date' => Carbon::today(),
            'assigned_by' => $this->finance->id, 'location_id' => Location::create([
                'name' => 'Lokasi Uji', 'lat' => -6.2, 'lng' => 106.8, 'geofence_m' => 100,
            ])->id,
        ]);

        $newEvidence = Media::create([
            'kind' => 'evidence', 'path' => 'evidence/'.Str::uuid().'.jpg',
            'mime' => 'image/jpeg', 'bytes' => 1000,
            'sha256' => hash('sha256', (string) Str::uuid()),
            'exif_taken_at' => now(), 'uploaded_by' => $this->staff->id,
        ]);

        $new = $this->actingAs($this->staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/refills', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $old->cart_id,
                'evidence_media_id' => $newEvidence->id,
                'lines' => [['product_id' => $this->product->id, 'qty_requested' => 2]],
            ]);
        $new->assertCreated()->assertJsonPath('data.status', 'SUBMITTED');
        $this->assertNotSame($old->id, $new->json('data.id'));
        $this->actingAs($this->staff)->getJson('/api/v1/refills/'.$old->id)
            ->assertOk()->assertJsonPath('data.status', 'EXPIRED')
            ->assertJsonPath('data.status_label', 'Kedaluwarsa')
            ->assertJsonPath('data.status_message', 'Request ini tidak selesai pada tanggal operasionalnya. Rider dapat mengajukan request baru; Finance memutuskan barang fisik secara terpisah.');
        $this->actingAs($this->staff)->getJson('/api/v1/refills')
            ->assertOk()->assertJsonFragment(['status' => 'EXPIRED']);
    }

    public function test_finance_recognition_is_bounded_balanced_and_idempotent(): void
    {
        $refill = $this->refill(RefillStatus::PICKED_UP, prepared: 5);
        Artisan::call('refills:expire-stale');
        $line = $refill->lines()->firstOrFail();
        $service = app(RefillExpiryDecisionService::class);

        try {
            $service->decide($refill, $this->finance, 'recognized', 'Cup ditemukan di lapangan.', [
                ['line_id' => $line->id, 'qty_recognized' => 6],
            ]);
            $this->fail('Quantity above prepared must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }
        $this->assertSame(0, StockLedger::count());

        $decision = $service->decide($refill, $this->finance, 'recognized', 'Cup ditemukan di lapangan.', [
            ['line_id' => $line->id, 'qty_recognized' => 3],
        ]);
        $this->assertSame(3, $decision->recognized_lines[$line->id]);
        $this->assertSame(1, $refill->expiryDecision()->count());
        $this->assertSame(-3, (int) StockLedger::where('location_type', 'kitchen')->sum('qty_delta'));
        $this->assertSame(3, (int) StockLedger::where('location_type', 'cart')->sum('qty_delta'));
        $this->assertSame(0, (int) StockLedger::sum('qty_delta'));
        $this->assertSame(2, StockLedger::count());
        $this->assertSame('2026-10-17', StockLedger::firstOrFail()->created_at->toDateString());
        $todayDraft = app(SettlementService::class)->draft($refill->cart, Carbon::today());
        $priorDraft = app(SettlementService::class)->draft($refill->cart, Carbon::parse('2026-10-17'));
        $this->assertSame(0, $todayDraft['lines'][0]['qty_issued']);
        $this->assertSame(3, $priorDraft['lines'][0]['qty_issued']);

        $again = $service->decide($refill, $this->finance, 'recognized', 'Cup ditemukan di lapangan.', [
            ['line_id' => $line->id, 'qty_recognized' => 3],
        ]);
        $this->assertSame($decision->id, $again->id);
        $this->assertSame(2, StockLedger::count());
        try {
            $service->decide($refill, $this->finance, 'recognized', 'Cup ditemukan di lapangan.', [
                ['line_id' => $line->id, 'qty_recognized' => 4],
            ]);
            $this->fail('A conflicting repeat must fail.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(RefillStatus::EXPIRED, $refill->fresh()->status);
    }

    public function test_finance_can_confirm_unrecorded_prepared_cups_on_an_expired_preparing_request(): void
    {
        $refill = $this->refill(RefillStatus::PREPARING);
        Artisan::call('refills:expire-stale');
        $line = $refill->lines()->firstOrFail();
        $decision = app(RefillExpiryDecisionService::class)->decide(
            $refill, $this->finance, 'recognized', 'Tiga cup tersedia secara fisik.', [
                ['line_id' => $line->id, 'qty_prepared_confirmed' => 3, 'qty_recognized' => 2],
            ],
        );
        $this->assertSame(3, $decision->confirmed_prepared_lines[$line->id]);
        $this->assertSame(2, $decision->recognized_lines[$line->id]);
        $this->assertSame(2, StockLedger::count());
        $this->assertSame(0, (int) StockLedger::sum('qty_delta'));
    }

    public function test_recognition_after_reconciliation_flags_the_snapshot_for_finance_without_rewriting_it(): void
    {
        $refill = $this->refill(RefillStatus::PICKED_UP, prepared: 5);
        $reconciledAt = Carbon::parse('2026-10-17 22:00:00');
        $settlement = Settlement::create([
            'operating_date' => $refill->operating_date, 'cart_id' => $refill->cart_id,
            'staff_id' => $this->staff->id, 'status' => 'RECONCILED',
            'reconciled_by' => $this->finance->id, 'reconciled_at' => $reconciledAt,
        ]);
        $snapshot = SettlementLine::create([
            'settlement_id' => $settlement->id, 'product_id' => $this->product->id,
            'qty_issued' => 0, 'qty_sold' => 0, 'qty_remaining' => 0,
        ]);
        Artisan::call('refills:expire-stale');
        app(RefillExpiryDecisionService::class)->decide(
            $refill, $this->finance, 'recognized', 'Dua cup ditemukan setelah setoran.', [
                ['line_id' => $refill->lines()->firstOrFail()->id, 'qty_recognized' => 2],
            ],
        );

        $this->assertTrue($settlement->fresh()->expected_stale);
        $this->assertSame(0, $settlement->fresh()->stale_delta_minor);
        $this->assertTrue($settlement->fresh()->reconciled_at->equalTo($reconciledAt));
        $this->assertSame(0, $snapshot->fresh()->qty_issued);
        $this->assertSame(2, app(SettlementService::class)->draft($refill->cart, $refill->operating_date)['lines'][0]['qty_issued']);
        $this->assertSame(1, OutboxEvent::where('name', 'SettlementExpectedStale')->count());
        $this->assertSame(0, (int) StockLedger::sum('qty_delta'));
    }

    public function test_direct_stale_write_is_expired_before_any_delivery_stock_can_post(): void
    {
        $approved = $this->refill(RefillStatus::APPROVED);
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/refills/'.$approved->id.'/start-preparing')->assertStatus(409);
        $this->assertSame(RefillStatus::EXPIRED, $approved->fresh()->status);

        $picked = $this->refill(RefillStatus::PICKED_UP, prepared: 5);
        $runner = User::factory()->role(Role::RIDER)->create();
        $picked->rider_id = $runner->id;
        $picked->save();
        try {
            app(RefillRequestStateMachine::class)->deliver($picked, $runner, []);
            $this->fail('A stale pickup must not deliver.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(RefillStatus::EXPIRED, $picked->fresh()->status);
        $this->assertSame(0, StockLedger::count());
    }

    public function test_failed_decision_never_posts_stock_and_wrong_role_gets_403(): void
    {
        $refill = $this->refill(RefillStatus::PREPARING);
        Artisan::call('refills:expire-stale');
        $service = app(RefillExpiryDecisionService::class);

        try {
            $service->decide($refill, $this->barista, 'failed', 'Barang tidak terkirim.');
            $this->fail('Produksi must be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            $service->decide($refill, $this->finance, 'failed', 'Singkat');
            $this->fail('Short reason must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $decision = $service->decide($refill, $this->finance, 'failed', 'Barang tidak terkirim.');
        $this->assertSame('failed', $decision->outcome);
        $this->assertSame($this->finance->id, $decision->decided_by);
        $this->assertNotNull($decision->decided_at);
        $this->assertSame(0, StockLedger::count());
        $this->actingAs($this->barista)->get(RefillRequestResource::getUrl())->assertForbidden();
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create())
            ->get(RefillRequestResource::getUrl())->assertOk();
        PermissionMatrix::set(Role::FINANCE, PanelModule::SETTLEMENTS, ['view']);
        $this->actingAs($this->finance)->get(RefillRequestResource::getUrl())->assertOk();
    }

    public function test_finance_can_use_both_cms_decision_actions(): void
    {
        $failed = $this->refill(RefillStatus::PREPARING);
        $recognized = $this->refill(RefillStatus::READY_TO_PICK, prepared: 5);
        Artisan::call('refills:expire-stale');
        $this->actingAs($this->finance);

        Livewire::test(ListRefillRequests::class)
            ->callTableAction('failed', $failed, ['reason' => 'Tidak ada barang fisik.'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('failed', $failed->expiryDecision()->firstOrFail()->outcome);

        Livewire::test(ListRefillRequests::class)
            ->callTableAction('recognize', $recognized, [
                'reason' => 'Tiga cup sudah diterima.',
                'lines' => [[
                    'line_id' => $recognized->lines()->firstOrFail()->id,
                    'qty_recognized' => 3,
                    'qty_prepared_confirmed' => 5,
                ]],
            ])->assertHasNoTableActionErrors();
        $this->assertSame('recognized', $recognized->expiryDecision()->firstOrFail()->outcome);
        $this->assertSame(2, StockLedger::count());
    }
}
