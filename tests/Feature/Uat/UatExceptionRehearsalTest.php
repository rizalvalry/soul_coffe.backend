<?php

namespace Tests\Feature\Uat;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\AppNotification;
use App\Models\Cart;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CentralKitchen;
use App\Models\DeliveryIncident;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Media;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RefillRequest;
use App\Models\RiderClosing;
use App\Models\RiderLocationSetup;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\CashTransactionService;
use App\Services\DirectSaleService;
use App\Services\StockAdjustmentService;
use App\Services\StockLedgerService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class UatExceptionRehearsalTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Product $product;

    private User $production;

    private User $rider;

    private User $runner;

    private User $finance;

    private User $management;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow('2026-10-22 10:00:00');
        config(['outbox.publish_mode' => 'queue']);
        $this->seed(AccountSeeder::class);
        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Exception', 'address' => 'Jl. Uji', 'open_at' => '00:00:00',
            'close_at' => '23:59:59', 'lat' => -6.2, 'lng' => 106.8, 'is_active' => true,
        ]);
        $this->cart = Cart::create(['code' => 'UAT-E', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $location = Location::create(['name' => 'Titik Exception', 'lat' => -6.2, 'lng' => 106.8]);
        $this->product = Product::create([
            'code' => 'UAT-E-A', 'name' => 'Produk A', 'unit' => 'cup', 'is_sellable' => true,
            'sort_order' => 1, 'is_active' => true,
        ]);
        ProductPriceVersion::create([
            'product_id' => $this->product->id, 'cost_price_minor' => 8000,
            'sell_price_minor' => 20000, 'effective_from' => now()->subDay(),
        ]);
        $this->production = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        $this->rider = User::factory()->role(Role::STAFF)->create();
        $this->runner = User::factory()->role(Role::RIDER)->create();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        RiderLocationSetup::create([
            'rider_id' => $this->rider->id, 'location_id' => $location->id,
            'basis' => 'management', 'changed_by' => $this->admin->id,
        ]);
        StaffAssignment::create([
            'source' => 'hand_to_cart', 'user_id' => $this->rider->id, 'cart_id' => $this->cart->id,
            'location_id' => $location->id, 'operating_date' => today(),
            'assigned_by' => $this->admin->id, 'kitchen_id' => $this->kitchen->id,
        ]);
        $this->postAs($this->production, 'absen', ['gps_lat' => -6.2, 'gps_lng' => 106.8])->assertCreated();
        $this->postAs($this->production, 'absen/open')->assertOk();
        $this->postAs($this->rider, 'absen', ['gps_lat' => -6.2, 'gps_lng' => 106.8])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function postAs(User $actor, string $path, array $data = [])
    {
        return $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/'.$path, $data);
    }

    private function issue(string $location, int $qty): void
    {
        app(StockLedgerService::class)->post($location, $location === StockLedgerService::CART ? $this->cart->id : $this->kitchen->id,
            $this->product->id, $location === StockLedgerService::CART ? MovementType::ALLOCATION_IN : MovementType::PRODUCTION_IN,
            $qty, $this->production->id, $this->kitchen->id);
    }

    private function evidence(): Media
    {
        return Media::create([
            'kind' => 'evidence', 'path' => 'evidence/'.Str::uuid().'.jpg', 'mime' => 'image/jpeg',
            'bytes' => 1024, 'sha256' => hash('sha256', (string) Str::uuid()),
            'exif_taken_at' => now(), 'uploaded_by' => $this->rider->id,
        ]);
    }

    private function refill(): RefillRequest
    {
        $id = $this->postAs($this->rider, 'refills', [
            'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
            'evidence_media_id' => $this->evidence()->id,
            'lines' => [['product_id' => $this->product->id, 'qty_requested' => 5]],
        ])->assertCreated()->json('data.id');

        return RefillRequest::findOrFail($id);
    }

    private function closing(int $remaining = 3, int $wasted = 0, float $lat = -6.2): array
    {
        return [
            'lines' => [['product_id' => $this->product->id, 'qty_remaining' => $remaining, 'qty_wasted' => $wasted]],
            'qris' => 0, 'transfer' => 0, 'gps_lat' => $lat, 'gps_lng' => 106.8,
        ];
    }

    public function test_e1_e5_e6_e7_e10_variances_are_recorded_and_period_close_waits_for_approval(): void
    {
        $this->issue(StockLedgerService::CART, 10);
        // E5: location suspicion is evidence, never a gate.
        $this->postAs($this->rider, 'me/closing', $this->closing(lat: -7.0))
            ->assertCreated()->assertJsonPath('data.outside_closing_point', true);
        $this->assertTrue(RiderClosing::query()->sole()->outside_closing_point);
        $this->assertTrue(OutboxEvent::query()->where('name', 'RiderClosingOutsidePoint')->exists());
        $outsideRecipients = AppNotification::query()->where('type', 'RiderClosingOutsidePoint')->pluck('user_id')->all();
        $this->assertContains($this->admin->id, $outsideRecipients);
        $this->assertContains($this->finance->id, $outsideRecipients);
        $this->assertNotContains($this->rider->id, $outsideRecipients);

        // E1: a short deposit is saved and reported to all money decision holders.
        $settlementId = $this->postAs($this->finance, 'settlements', [
            'cart_id' => $this->cart->id, 'cash' => 130000, 'qris' => 0, 'transfer' => 0,
        ])->assertCreated()->json('data.id');
        $settlement = Settlement::findOrFail($settlementId);
        $this->assertSame('VARIANCE_FLAGGED', $settlement->status);
        $this->assertSame(-10000, $settlement->variance_minor);
        $varianceRecipients = AppNotification::query()->where('type', 'SettlementVarianceFlagged')->pluck('user_id')->all();
        foreach ([$this->admin, $this->finance, $this->management] as $user) {
            $this->assertContains($user->id, $varianceRecipients);
        }

        // E6: physical return differs from Closing by one cup; close-out still succeeds.
        $this->postAs($this->production, 'showcase/close-out', [
            'cart_id' => $this->cart->id,
            'returned' => [['product_id' => $this->product->id, 'qty' => 2]],
        ])->assertOk();
        $this->assertDatabaseHas('cart_closeout_variances', [
            'cart_id' => $this->cart->id, 'product_id' => $this->product->id,
            'qty_closing_remaining' => 3, 'qty_counted' => 2, 'variance_qty' => -1,
        ]);

        // E7: a corrected Closing makes the recorded snapshot stale until Finance records again.
        $this->postAs($this->rider, 'me/closing', $this->closing(4, 0, -7.0))->assertOk();
        $this->assertTrue($settlement->fresh()->expected_stale);
        $this->postAs($this->finance, "settlements/{$settlementId}/approve", ['lines' => []])->assertUnprocessable();
        // E10: the period cannot be closed while the setoran is pending.
        try {
            app(AccountingPeriodService::class)->setStatus(2026, 10, 'CLOSED', $this->management);
            $this->fail('Period closed with a pending settlement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('period', $exception->errors());
        }
        $this->postAs($this->finance, 'settlements', [
            'cart_id' => $this->cart->id, 'cash' => 120000, 'qris' => 0, 'transfer' => 0,
        ])->assertCreated();
        $this->assertFalse($settlement->fresh()->expected_stale);
        $this->postAs($this->finance, "settlements/{$settlementId}/approve", ['lines' => []])->assertOk();
    }

    public function test_e2_e3_e4_refill_requires_reason_order_and_partial_incident_resolution(): void
    {
        $this->issue(StockLedgerService::KITCHEN, 5);
        $refill = $this->refill();
        $lineId = $refill->lines()->firstOrFail()->id;
        $this->postAs($this->finance, "refills/{$refill->id}/approve", [
            'version' => $refill->version,
            'lines' => [['line_id' => $lineId, 'qty_approved' => 4]],
        ])->assertUnprocessable()->assertJsonValidationErrors('partial_reason');
        $this->postAs($this->production, "refills/{$refill->id}/start-preparing")->assertStatus(409);
        $this->postAs($this->finance, "refills/{$refill->id}/approve", [
            'version' => $refill->version, 'partial_reason' => 'Satu cup belum siap',
            'lines' => [['line_id' => $lineId, 'qty_approved' => 4]],
        ])->assertOk();
        $this->postAs($this->production, "refills/{$refill->id}/start-preparing")->assertOk();
        $this->postAs($this->production, "refills/{$refill->id}/ready", [
            'lines' => [['line_id' => $lineId, 'qty_prepared' => 4]],
        ])->assertOk();
        $this->postAs($this->runner, "refills/{$refill->id}/claim")->assertOk();
        $this->actingAs($this->runner, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post("/api/v1/refills/{$refill->id}/incident", [
                'uuid' => (string) Str::uuid(), 'photo' => UploadedFile::fake()->image('tumpah.jpg'),
                'photo_taken_at' => now()->toIso8601String(),
                'lines' => json_encode([['line_id' => $lineId, 'qty_damaged' => 1]]),
                'note' => 'Satu cup tumpah di jalan.',
            ], ['Accept' => 'application/json'])->assertCreated();
        $incident = DeliveryIncident::query()->sole();
        $this->postAs($this->finance, "incidents/{$incident->id}/resolve", [
            'mode' => 'partial', 'note' => 'Tiga cup sisanya lanjut dikirim.',
        ])->assertSuccessful();
        $this->assertSame(3, $refill->lines()->firstOrFail()->qty_prepared);
        $this->actingAs($this->runner, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post("/api/v1/refills/{$refill->id}/deliver", [
                'handover_media_id' => Media::create([
                    'kind' => 'handover', 'path' => 'handover/'.Str::uuid().'.jpg', 'mime' => 'image/jpeg',
                    'bytes' => 1024, 'sha256' => hash('sha256', (string) Str::uuid()),
                    'exif_taken_at' => now(), 'uploaded_by' => $this->runner->id,
                ])->id,
                'lines' => json_encode([['line_id' => $lineId, 'qty_received' => 3]]),
                'signature_method' => 'staff_signature', 'stroke_count' => 3,
                'signature' => UploadedFile::fake()->image('ttd.png', 320, 120),
            ], ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertSame(3, app(StockLedgerService::class)->stockFor(
            StockLedgerService::CART, $this->cart->id, $this->product->id
        ));
    }

    public function test_e8_e9_e11_e12_e13_reversal_audit_permissions_and_validation(): void
    {
        $this->issue(StockLedgerService::CART, 5);
        $directSale = app(DirectSaleService::class)->record($this->finance, $this->cart->id,
            [['product_id' => $this->product->id, 'qty' => 2]]);
        $this->assertSame(3, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        app(DirectSaleService::class)->void($directSale, $this->finance, 'Salah input');
        $this->assertSame(5, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id));
        $this->assertNotNull(JournalEntry::query()->where('source_type', 'direct_sale')->firstOrFail()->fresh()->reversal);

        $cashTx = app(CashTransactionService::class)->record($this->finance, [
            'uuid' => (string) Str::uuid(), 'date' => today()->toDateString(),
            'cash_category_id' => CashCategory::query()->where('name', 'Setor tunai ke bank')->value('id'),
            'cash_account_id' => CashAccount::query()->where('name', 'Kas Setoran')->value('id'),
            'amount_minor' => 100000, 'description' => 'Selisih setor bank dicatat',
        ]);
        $this->assertSame('POSTED', $cashTx->status);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'cash_transaction.balance_exceeded', 'subject_id' => $cashTx->id,
        ]);

        Livewire::actingAs($this->finance)->test(CreateUser::class)->fillForm([
            'name' => 'Administrator Baru', 'phone_e164' => '081298765063',
            'role' => Role::ADMINISTRATOR->value, 'password' => 'rahasia123', 'is_active' => true,
        ])->call('create')->assertForbidden();
        $this->assertDatabaseMissing('users', ['phone_e164' => '081298765063']);
        $disabled = User::factory()->role(Role::STAFF)->create(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', [
            'phone' => $disabled->phone_e164, 'password' => 'password', 'device_name' => 'uat',
        ])->assertUnauthorized();

        try {
            app(StockAdjustmentService::class)->adjust(StockLedgerService::CART, $this->cart->id,
                $this->product->id, 4, '', $this->admin);
            $this->fail('Stock correction without a reason was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('alasan', mb_strtolower($exception->getMessage()));
        }
        $adjustment = app(StockAdjustmentService::class)->adjust(StockLedgerService::CART, $this->cart->id,
            $this->product->id, 4, 'Hitung ulang stok sore ini', $this->admin);
        $this->assertSame(MovementType::ADJUSTMENT, $adjustment->movement_type);
        $this->assertSame('stock_adjustment', $adjustment->ref_type);
        $this->assertSame($this->admin->id, $adjustment->actor_id);
        $this->assertSame('Hitung ulang stok sore ini', $adjustment->note);
    }
}
