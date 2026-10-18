<?php

namespace Tests\Feature\E2E;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\PayrollConfiguration;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Productions\Pages\ListProductions;
use App\Filament\Resources\Productions\ProductionResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\PriceVersionsRelationManager;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Users\UserResource;
use App\Http\Resources\AllocationResource;
use App\Models\Cart;
use App\Models\CashAccount;
use App\Models\CentralKitchen;
use App\Models\DailyAllocation;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Media;
use App\Models\PayrollComponentEntry;
use App\Models\PayrollComponentType;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RawMaterial;
use App\Models\RefillRequest;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\AttendanceService;
use App\Services\CentralStockService;
use App\Services\PayrollCalculator;
use App\Services\ProductionService;
use App\Services\PurchaseOrderService;
use App\Services\RecipeService;
use App\Services\RefillExpiryDecisionService;
use App\Services\SaleService;
use App\Services\SettlementService;
use App\Services\StockLedgerService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PayrollConfigurationSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessProcessAuditTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Location $location;

    private Product $product;

    private User $admin;

    private User $finance;

    private User $barista;

    private User $staff;

    private User $runner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['soul.closing_started_on' => today()->addDay()->toDateString()]);
        Carbon::setTestNow('2026-10-18 10:00:00');
        PermissionMatrix::forget();
        $this->seed(AccountSeeder::class);
        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Audit', 'address' => 'Jl. Uji', 'open_at' => '00:00:00',
            'close_at' => '23:59:59', 'is_active' => true,
        ]);
        $this->cart = Cart::create(['code' => 'T036', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $this->location = Location::create(['name' => 'Titik Uji', 'lat' => -6.2, 'lng' => 106.8]);
        $this->product = Product::create([
            'code' => 'AUDIT-CUP', 'name' => 'Kopi Audit', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
        ProductPriceVersion::create([
            'product_id' => $this->product->id, 'cost_price_minor' => 8000,
            'sell_price_minor' => 20000, 'effective_from' => now()->subDay(),
        ]);
        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        $this->staff = User::factory()->role(Role::STAFF)->create();
        $this->runner = User::factory()->role(Role::RIDER)->create();
        StaffAssignment::create([
            'source' => 'hand_to_cart',
            'user_id' => $this->staff->id, 'cart_id' => $this->cart->id,
            'location_id' => $this->location->id, 'operating_date' => today(),
            'assigned_by' => $this->admin->id, 'kitchen_id' => $this->kitchen->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PermissionMatrix::forget();
        parent::tearDown();
    }

    private function openDay(): void
    {
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista);
        $attendance->openStaffWindow($this->barista);
        $attendance->clockIn($this->staff);
    }

    private function evidence(string $kind, User $actor): Media
    {
        return Media::create([
            'kind' => $kind, 'path' => $kind.'/'.Str::uuid().'.jpg', 'mime' => 'image/jpeg',
            'bytes' => 1024, 'sha256' => hash('sha256', (string) Str::uuid()),
            'exif_taken_at' => now(), 'uploaded_by' => $actor->id,
        ]);
    }

    private function refill(int $qty = 4): RefillRequest
    {
        $id = $this->actingAs($this->staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/refills', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
                'evidence_media_id' => $this->evidence('evidence', $this->staff)->id,
                'lines' => [['product_id' => $this->product->id, 'qty_requested' => $qty]],
            ])->assertCreated()->json('data.id');

        return RefillRequest::findOrFail($id);
    }

    private function deliveredRefill(int $qty = 4): RefillRequest
    {
        $refill = $this->refill($qty);
        $lineId = $refill->lines()->firstOrFail()->id;
        $this->actingAs($this->finance)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/approve", [
                'version' => $refill->version,
                'lines' => [['line_id' => $lineId, 'qty_approved' => $qty]],
            ])->assertOk();
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/start-preparing")->assertOk();
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/ready", [
                'lines' => [['line_id' => $lineId, 'qty_prepared' => $qty]],
            ])->assertOk();
        $this->actingAs($this->runner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/claim")->assertOk();
        $this->actingAs($this->runner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post("/api/v1/refills/{$refill->id}/deliver", [
                'handover_media_id' => $this->evidence('handover', $this->runner)->id,
                'lines' => json_encode([['line_id' => $lineId, 'qty_received' => $qty]]),
                'signature_method' => 'staff_signature', 'stroke_count' => 3,
                'signature' => UploadedFile::fake()->image('tanda-tangan.png', 320, 120),
            ], ['Accept' => 'application/json'])->assertSuccessful();

        return $refill->fresh();
    }

    private function stock(string $location, int $locationId): int
    {
        return app(StockLedgerService::class)->stockFor($location, $locationId, $this->product->id);
    }

    public function test_01_complete_operating_day_from_purchase_to_balanced_settlement(): void
    {
        $material = RawMaterial::create([
            'code' => 'BAHAN-T036', 'name' => 'Bahan Audit', 'unit' => 'ml',
            'is_active' => true, 'sort_order' => 1,
        ]);
        $supplier = Supplier::create(['code' => 'SUP-T036', 'name' => 'Supplier Audit', 'is_active' => true]);
        app(RecipeService::class)->create($this->product->id, [
            ['raw_material_id' => $material->id, 'qty_per_unit' => 100],
        ], $this->admin);
        $orders = app(PurchaseOrderService::class);
        $po = $orders->create($supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $material->id, 'qty_ordered' => 3000, 'unit_cost_minor' => 10],
        ], $this->finance);
        $orders->receive($orders->markOrdered($po, $this->finance), [], $this->finance);
        $receiptJournal = JournalEntry::query()->where('source_type', 'purchase_order')
            ->where('source_id', $po->id)->where('purpose', 'RECEIPT')->with('lines.account')->sole();
        $this->assertSame(30000, (int) $receiptJournal->lines->sum('debit_minor'));
        $this->assertSame(30000, (int) $receiptJournal->lines->sum('credit_minor'));
        $this->assertSame('1-1700', $receiptJournal->lines->firstWhere('debit_minor', 30000)->account->code);
        $this->assertSame('2-1400', $receiptJournal->lines->firstWhere('credit_minor', 30000)->account->code);
        $this->assertSame(3000, app(StockLedgerService::class)->rawMaterialStockFor(
            StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $material->id,
        ));

        $this->openDay();
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/showcase/brew', [
                'lines' => [['product_id' => $this->product->id, 'qty' => 30]],
            ])->assertOk();
        $this->assertSame(0, app(StockLedgerService::class)->rawMaterialStockFor(
            StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $material->id,
        ));
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 10]],
            ])->assertCreated();
        $this->assertSame(10, $this->stock(StockLedgerService::CART, $this->cart->id));

        $this->actingAs($this->staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id, 'payment_method' => 'cash',
                'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
            ])->assertCreated();
        $this->actingAs($this->staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id, 'payment_method' => 'qris',
                'lines' => [['product_id' => $this->product->id, 'qty' => 3]],
            ])->assertCreated();
        $refill = $this->deliveredRefill();
        $this->assertContains($refill->status->value, ['DELIVERED', 'CLOSED']);
        $this->assertNotNull($refill->signature_id);
        $this->assertSame(9, $this->stock(StockLedgerService::CART, $this->cart->id));
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/showcase/close-out', [
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 8]],
                'rejected' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertOk();
        $this->assertSame(0, $this->stock(StockLedgerService::CART, $this->cart->id));

        $settlementId = $this->actingAs($this->finance)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 40000, 'qris' => 60000, 'transfer' => 0,
            ])->assertCreated()->json('data.id');
        $settlement = Settlement::findOrFail($settlementId);
        $this->assertSame(0, $settlement->variance_minor);
        $this->actingAs($this->finance)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlementId}/approve", ['lines' => []])->assertOk();
        foreach (['SETTLEMENT', 'SETTLEMENT_COGS'] as $purpose) {
            $journal = JournalEntry::query()->where('source_type', 'settlement')
                ->where('source_id', $settlement->id)->where('purpose', $purpose)->with('lines')->sole();
            $this->assertSame($journal->lines->sum('debit_minor'), $journal->lines->sum('credit_minor'));
        }
        $movements = DB::table('stock_ledger')->where('location_type', StockLedgerService::CART)
            ->where('location_id', $this->cart->id)->where('product_id', $this->product->id);
        $quantity = fn (MovementType $type): int => (int) (clone $movements)
            ->where('movement_type', $type->value)->sum('qty_delta');
        $issued = $quantity(MovementType::ALLOCATION_IN);
        $refilled = $quantity(MovementType::REFILL_IN);
        $sold = (int) DB::table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.cart_id', $this->cart->id)->where('sale_lines.product_id', $this->product->id)
            ->sum('sale_lines.qty');
        $returned = -$quantity(MovementType::RETURN_OUT);
        $waste = -$quantity(MovementType::WASTE_OUT);
        $this->assertSame($sold, -$quantity(MovementType::SALE_OUT));
        $this->assertSame($issued + $refilled, $sold + $returned + $waste);
        $this->assertSame([10, 4, 5, 8, 1], [$issued, $refilled, $sold, $returned, $waste]);
        $this->assertDatabaseHas('stock_ledger', ['movement_type' => MovementType::WASTE_OUT->value, 'qty_delta' => -1]);
    }

    public function test_02_closed_day_blocks_both_allocation_paths_and_rider_sale_but_not_other_roles(): void
    {
        $allocation = [
            'operating_date' => today()->toDateString(), 'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id, 'location_id' => $this->location->id,
            'lines' => [['product_id' => $this->product->id, 'qty_issued' => 1]],
        ];
        $handover = [
            'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
        ];
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/allocations', $allocation)->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_OPEN');
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/showcase/hand-to-cart', $handover)->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_OPEN');
        $this->actingAs($this->staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_OPEN');
        $this->actingAs($this->finance)->getJson('/api/v1/settlements/queue')->assertOk();
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($management)->getJson('/api/v1/settlements/queue')->assertOk();
        $this->actingAs($this->runner)->getJson('/api/v1/refills')->assertOk();
        $this->actingAs($this->barista)->getJson('/api/v1/showcase/stock')->assertOk();
        $this->assertDatabaseCount('daily_allocations', 0);
        $this->assertSame(0, $this->stock(StockLedgerService::CART, $this->cart->id));

        $this->openDay();
        app(ProductionService::class)->brew($this->kitchen->id, [$this->product->id => 1], $this->barista);
        app(CentralStockService::class)->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 1]);
        $refill = $this->refill(1);
        DB::table('staff_attendance_windows')->whereDate('operating_date', today())->delete();
        $this->actingAs($this->staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/refills', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
                'evidence_media_id' => $this->evidence('evidence', $this->staff)->id,
                'lines' => [['product_id' => $this->product->id, 'qty_requested' => 1]],
            ])->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_OPEN');
        $this->actingAs($this->staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/cancel")
            ->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_OPEN');
        $this->actingAs($this->staff)->post('/api/v1/media/evidence', [
            'file' => UploadedFile::fake()->image('bukti.jpg', 400, 400),
            'taken_at' => now()->toIso8601String(),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_OPEN');
        $this->actingAs($this->runner)->getJson('/api/v1/refills')->assertOk();
        app(AttendanceService::class)->openStaffWindow($this->barista);
        DB::table('attendances')->where('user_id', $this->staff->id)->delete();
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/allocations', $allocation)->assertStatus(422);
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/showcase/hand-to-cart', $handover)->assertStatus(422);
        $this->actingAs($this->staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/showcase/hand-to-cart', $handover)->assertForbidden();
        $this->actingAs($this->runner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/allocations', $allocation)->assertForbidden();
    }

    public function test_03_stale_refill_expires_without_stock_and_finance_decision_is_idempotent(): void
    {
        $this->openDay();
        $refill = $this->refill();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Artisan::call('refills:expire-stale');
        Artisan::call('refills:expire-stale');
        $this->assertSame('EXPIRED', $refill->fresh()->status->value);
        $this->assertDatabaseCount('stock_ledger', 0);
        $decision = app(RefillExpiryDecisionService::class)->decide($refill, $this->finance, 'failed', 'Barang tidak pernah dikirim.');
        $replay = app(RefillExpiryDecisionService::class)->decide($refill, $this->finance, 'failed', 'Barang tidak pernah dikirim.');
        $this->assertSame($decision->id, $replay->id);
        $this->assertDatabaseCount('stock_ledger', 0);
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista);
        $attendance->openStaffWindow($this->barista);
        $attendance->clockIn($this->staff);
        StaffAssignment::create([
            'source' => 'hand_to_cart',
            'user_id' => $this->staff->id, 'cart_id' => $this->cart->id,
            'location_id' => $this->location->id, 'operating_date' => today(),
            'assigned_by' => $this->admin->id, 'kitchen_id' => $this->kitchen->id,
        ]);
        $new = $this->refill();
        $this->assertNotSame($refill->id, $new->id);
        app(ProductionService::class)->brew($this->kitchen->id, [$this->product->id => 5], $this->barista);
        $line = $new->lines()->firstOrFail();
        $this->actingAs($this->finance)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$new->id}/approve", [
                'version' => $new->version, 'lines' => [['line_id' => $line->id, 'qty_approved' => 4]],
            ])->assertOk();
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$new->id}/start-preparing")->assertOk();
        $this->actingAs($this->barista)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$new->id}/ready", [
                'lines' => [['line_id' => $line->id, 'qty_prepared' => 4]],
            ])->assertOk();
        Carbon::setTestNow('2026-10-22 10:00:00');
        Artisan::call('refills:expire-stale');
        $recognized = app(RefillExpiryDecisionService::class)->decide($new, $this->finance, 'recognized', 'Tiga cup sudah diterima Rider.', [
            ['line_id' => $line->id, 'qty_recognized' => 3],
        ]);
        $same = app(RefillExpiryDecisionService::class)->decide($new, $this->finance, 'recognized', 'Tiga cup sudah diterima Rider.', [
            ['line_id' => $line->id, 'qty_recognized' => 3],
        ]);
        $this->assertSame($recognized->id, $same->id);
        $this->assertSame(3, $this->stock(StockLedgerService::CART, $this->cart->id));
        $this->assertSame(2, $this->stock(StockLedgerService::KITCHEN, $this->kitchen->id));
    }

    public function test_04_variance_is_flagged_visible_in_finance_cms_and_journal_balances(): void
    {
        $this->openDay();
        app(ProductionService::class)->brew($this->kitchen->id, [$this->product->id => 3], $this->barista);
        app(CentralStockService::class)->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 3]);
        app(SaleService::class)->record($this->staff, [['product_id' => $this->product->id, 'qty' => 2]], (string) Str::uuid(), submittedCartId: $this->cart->id);
        $settlement = app(SettlementService::class)->record($this->cart, $this->finance, ['cash' => 30000, 'qris' => 0, 'transfer' => 0]);
        $this->assertSame('VARIANCE_FLAGGED', $settlement->status);
        PermissionMatrix::set(Role::FINANCE, PanelModule::SETTLEMENTS, ['view', 'edit']);
        $this->actingAs($this->finance)->get('/admin/settlements')->assertOk()->assertSee('Perlu ditinjau');
        app(SettlementService::class)->approve($settlement, $this->finance, [
            ['product_id' => $this->product->id, 'qty_returned' => 1, 'qty_rejected' => 0],
        ]);
        $journal = JournalEntry::query()->where('source_type', 'settlement')
            ->where('source_id', $settlement->id)->where('purpose', 'SETTLEMENT')->with('lines.account')->sole();
        $this->assertSame($journal->lines->sum('debit_minor'), $journal->lines->sum('credit_minor'));
        $short = $journal->lines->first(fn ($line): bool => $line->account->code === '6-1100');
        $this->assertSame(10000, $short->debit_minor);
        $this->assertSame(0, $short->credit_minor);

        $otherCart = Cart::create(['code' => 'T036-B', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $otherStaff = User::factory()->role(Role::STAFF)->create();
        StaffAssignment::create([
            'source' => 'hand_to_cart',
            'user_id' => $otherStaff->id, 'cart_id' => $otherCart->id,
            'location_id' => $this->location->id, 'operating_date' => today(),
            'assigned_by' => $this->admin->id, 'kitchen_id' => $this->kitchen->id,
        ]);
        app(AttendanceService::class)->clockIn($otherStaff);
        app(ProductionService::class)->brew($this->kitchen->id, [$this->product->id => 3], $this->barista);
        app(CentralStockService::class)->handToCart($this->barista, $otherCart, $otherStaff, [$this->product->id => 3]);
        app(SaleService::class)->record($otherStaff, [['product_id' => $this->product->id, 'qty' => 2]], (string) Str::uuid(), submittedCartId: $otherCart->id);
        $over = app(SettlementService::class)->record($otherCart, $this->finance, ['cash' => 50000, 'qris' => 0, 'transfer' => 0]);
        $this->assertSame(10000, $over->variance_minor);
        $this->actingAs($this->finance)->get('/admin/settlements')->assertOk()->assertSee('Perlu ditinjau');
        app(SettlementService::class)->approve($over, $this->finance, [
            ['product_id' => $this->product->id, 'qty_returned' => 1, 'qty_rejected' => 0],
        ]);
        $overJournal = JournalEntry::query()->where('source_type', 'settlement')
            ->where('source_id', $over->id)->where('purpose', 'SETTLEMENT')->with('lines.account')->sole();
        $this->assertSame($overJournal->lines->sum('debit_minor'), $overJournal->lines->sum('credit_minor'));
        $excess = $overJournal->lines->first(fn ($line): bool => $line->account->code === '6-1100');
        $this->assertSame(0, $excess->debit_minor);
        $this->assertSame(10000, $excess->credit_minor);
    }

    public function test_05_cms_role_matrix_hides_cost_and_rejects_forbidden_resources(): void
    {
        $material = RawMaterial::create(['code' => 'ROLE-COST', 'name' => 'Bahan Rahasia', 'unit' => 'ml', 'is_active' => true, 'sort_order' => 1]);
        $supplier = Supplier::create(['code' => 'ROLE-SUP', 'name' => 'Supplier Uji', 'is_active' => true]);
        $po = app(PurchaseOrderService::class)->create($supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $material->id, 'qty_ordered' => 2, 'unit_cost_minor' => 12345],
        ], $this->finance);
        $po = app(PurchaseOrderService::class)->receive(
            app(PurchaseOrderService::class)->markOrdered($po, $this->finance), [], $this->finance,
        );
        foreach (Role::cases() as $role) {
            $user = User::factory()->role($role)->create();
            if ($role !== Role::ADMINISTRATOR) {
                PermissionMatrix::set($role, PanelModule::PRODUCTS, ['view']);
                PermissionMatrix::set($role, PanelModule::PURCHASE_ORDERS, ['view', 'create']);
            }
            $this->actingAs($user)->get(ProductResource::getUrl())->assertOk();
            $this->actingAs($user)->get(CreateProduct::getUrl())
                ->assertStatus($role === Role::ADMINISTRATOR ? 200 : 403);
            $this->actingAs($user)->get(UserResource::getUrl())
                ->assertStatus($role === Role::ADMINISTRATOR ? 200 : 403);
            $this->actingAs($user)->get(AccountResource::getUrl())
                ->assertStatus(in_array($role, [Role::ADMINISTRATOR, Role::FINANCE, Role::MANAGEMENT], true) ? 200 : 403);
            $body = $this->actingAs($user, 'sanctum')->getJson('/api/v1/products')->assertOk()->getContent();
            $privileged = in_array($role, [Role::ADMINISTRATOR, Role::FINANCE, Role::MANAGEMENT], true);
            $this->assertSame($privileged, str_contains($body, 'cost_price'));
            $this->assertSame($privileged, str_contains($body, 'sell_price'));
            $this->actingAs($user)->get(PurchaseOrderResource::getUrl())->assertOk();
            $purchaseRows = Livewire::test(ListPurchaseOrders::class);
            if ($privileged) {
                $purchaseRows->assertSee('@Rp12.345')->assertSee('Sisa hutang');
            } else {
                $purchaseRows->assertDontSee('@Rp12.345')->assertDontSee('Sisa hutang')->assertDontSee('24.690');
            }
            $this->assertSame($privileged, PurchaseOrderResource::canCreate());
            $table = Livewire::test(ListProducts::class);
            if ($privileged) {
                $table->assertSee('HPP');
            } else {
                $table->assertDontSee('HPP')->assertDontSee('8.000');
            }
            $history = Livewire::test(PriceVersionsRelationManager::class, [
                'ownerRecord' => $this->product, 'pageClass' => EditProduct::class,
            ]);
            if ($privileged) {
                $history->assertSee('HPP');
                if ($role !== Role::ADMINISTRATOR) {
                    $history->assertTableActionHidden('create');
                    $auth = new \ReflectionMethod($history->instance(), 'getCreateAuthorizationResponse');
                    $this->assertFalse($auth->invoke($history->instance())->allowed());
                }
            } else {
                $history->assertDontSee('HPP')->assertTableActionHidden('create');
                $auth = new \ReflectionMethod($history->instance(), 'getCreateAuthorizationResponse');
                $this->assertFalse($auth->invoke($history->instance())->allowed());
            }
            $this->actingAs($user)->get(PayrollConfiguration::getUrl())
                ->assertStatus($role === Role::MANAGEMENT ? 200 : 403);
        }
        PermissionMatrix::set(Role::BARISTA, PanelModule::PURCHASE_ORDERS, ['view', 'edit']);
        Livewire::actingAs($this->barista)->test(ListPurchaseOrders::class)
            ->assertTableActionHidden('markOrdered', $po)
            ->assertTableActionHidden('receive', $po)
            ->assertTableActionHidden('cancel', $po)
            ->assertTableActionHidden('payDebt', $po);
    }

    public function test_05b_view_only_finance_cannot_forge_price_write_but_edit_grant_can_create_version(): void
    {
        PermissionMatrix::set(Role::FINANCE, PanelModule::PRODUCTS, ['view']);
        $before = $this->product->priceVersions()->count();
        $forged = Livewire::actingAs($this->finance)->test(PriceVersionsRelationManager::class, [
            'ownerRecord' => $this->product, 'pageClass' => EditProduct::class,
        ])->assertTableActionHidden('create')->call('mountTableAction', 'create');
        $this->assertNull($forged->instance()->getMountedAction());
        $this->assertSame($before, $this->product->priceVersions()->count());

        PermissionMatrix::set(Role::FINANCE, PanelModule::PRODUCTS, ['view', 'edit']);
        Livewire::actingAs($this->finance)->test(PriceVersionsRelationManager::class, [
            'ownerRecord' => $this->product, 'pageClass' => EditProduct::class,
        ])->callTableAction('create', data: [
            'cost_price_minor' => 9000, 'sell_price_minor' => 21000,
            'effective_from' => now()->toDateTimeString(),
        ])->assertHasNoTableActionErrors();
        $this->assertSame($before + 1, $this->product->priceVersions()->count());
    }

    public function test_06_product_image_upload_replacement_and_missing_image_contract(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin);
        Livewire::test(CreateProduct::class)->fillForm([
            'code' => 'FOTO-T036', 'name' => 'Produk Foto', 'unit' => 'cup',
            'sort_order' => 2, 'is_sellable' => true, 'is_active' => true,
            'image_path' => [UploadedFile::fake()->image('awal.jpg', 500, 500)],
        ])->call('create')->assertHasNoFormErrors();
        $photo = Product::query()->where('code', 'FOTO-T036')->sole();
        $first = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/products')->json('data'))
            ->firstWhere('id', $photo->id)['image_url'];
        Livewire::test(EditProduct::class, ['record' => $photo->id])->fillForm([
            'image_path' => [UploadedFile::fake()->image('baru.jpg', 500, 500)],
        ])->call('save')->assertHasNoFormErrors();
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/products')->assertOk();
        $second = collect($response->json('data'))->firstWhere('id', $photo->id)['image_url'];
        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith('http', $second);
        $this->assertNull(collect($response->json('data'))->firstWhere('id', $this->product->id)['image_url']);
    }

    public function test_07_payroll_fails_closed_until_verified_rates_are_entered(): void
    {
        $this->seed(PayrollConfigurationSeeder::class);
        $this->assertDatabaseCount('payroll_settings', 0);
        $this->assertDatabaseHas('payroll_component_types', ['code' => 'MEAL']);
        $employee = User::factory()->role(Role::MANAGEMENT)->create();
        $employee->employeeProfile()->create([
            'base_salary_minor' => 10000000, 'ptkp_status' => 'TK/0',
            'payroll_active' => true, 'bpjs_tk_enrolled' => true,
        ]);
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::DASHBOARD, ['view']);
        $this->actingAs($employee)->get(PayrollConfiguration::getUrl())->assertOk();
        Livewire::test(PayrollConfiguration::class)
            ->set('preview.user_id', $employee->id)
            ->set('preview.period', '2026-10')
            ->call('previewPayroll')
            ->assertHasNoErrors()
            ->assertSee('Tarif BPJS periode ini belum diverifikasi');
        try {
            app(PayrollCalculator::class)->calculate($employee->refresh(), '2026-10');
            $this->fail('Payroll without verified rates must fail closed.');
        } catch (DomainException $error) {
            $this->assertStringContainsString('akuntan', $error->getMessage());
        }
        DB::table('payroll_settings')->insert([
            'effective_from' => '2026-01-01', 'jht_employer_bps' => 370, 'jht_employee_bps' => 200,
            'jp_employer_bps' => 200, 'jp_employee_bps' => 100,
            'jkk_employer_bps' => 24, 'jkk_employee_bps' => 0,
            'jkm_employer_bps' => 30, 'jkm_employee_bps' => 0,
            'health_employer_bps' => 400, 'health_employee_bps' => 100,
            'jp_wage_cap_minor' => 10000000, 'health_wage_cap_minor' => 12000000,
            'source_note' => 'Tarif sintetis terverifikasi hanya untuk tes',
            'verified_by' => $employee->id, 'verified_at' => now(),
        ]);
        DB::table('ptkp_rates')->insert([
            'status' => 'TK/0', 'ter_category' => 'A', 'annual_amount_minor' => 54000000,
            'effective_from' => '2026-01-01', 'source_note' => 'Tarif sintetis terverifikasi hanya untuk tes',
            'verified_by' => $employee->id, 'verified_at' => now(),
        ]);
        DB::table('payroll_ter_versions')->insert([
            'category' => 'A', 'effective_from' => '2026-01-01', 'published_at' => now(),
            'verified_by' => $employee->id, 'verified_at' => now(),
        ]);
        DB::table('pph21_ter_brackets')->insert([
            'category' => 'A', 'lower_gross_minor' => 0, 'upper_gross_minor' => null,
            'rate_bps' => 500, 'effective_from' => '2026-01-01',
            'source_note' => 'Tarif sintetis terverifikasi hanya untuk tes',
        ]);
        $pay = app(PayrollCalculator::class)->calculate($employee->refresh(), '2026-10');
        $this->assertSame($pay['gross_pay_minor'], $pay['take_home_minor'] + $pay['manual_deductions_minor']
            + $pay['bpjs_employee_total_minor'] + $pay['pph21_minor']);
        Livewire::test(PayrollConfiguration::class)
            ->set('preview.user_id', $employee->id)
            ->set('preview.period', '2026-10')
            ->call('previewPayroll')
            ->assertHasNoErrors()
            ->assertSee('Dibawa pulang');
        $profile = $employee->employeeProfile;
        $profile->base_salary_minor = PHP_INT_MAX;
        $profile->save();
        PayrollComponentEntry::create([
            'user_id' => $employee->id,
            'component_type_id' => PayrollComponentType::query()->where('code', 'MEAL')->sole()->id,
            'period_start' => '2026-10-01', 'request_key' => (string) Str::uuid(),
            'amount_minor' => 1, 'created_by' => $employee->id,
        ]);
        Livewire::test(PayrollConfiguration::class)
            ->set('preview.user_id', $employee->id)
            ->set('preview.period', '2026-10')
            ->call('previewPayroll')
            ->assertHasNoErrors()
            ->assertSee('Nominal payroll terlalu besar. Cek lagi angkanya ya.');
    }

    public function test_08_scheduler_commands_are_registered_and_repeatable(): void
    {
        Artisan::call('schedule:list');
        $schedule = Artisan::output();
        foreach (['soul:seed-daily-allowances', 'refills:expire-stale', 'queue:work'] as $command) {
            $this->assertStringContainsString($command, $schedule);
        }
        $this->assertStringNotContainsString('soul:carry-forward-staff-assignments', $schedule);
        $expiryEvent = collect(Schedule::events())->first(fn ($event): bool => str_contains($event->command, 'refills:expire-stale'));
        $this->assertNotNull($expiryEvent);
        $this->assertSame('0 * * * *', $expiryEvent->expression);
        $this->assertTrue($expiryEvent->withoutOverlapping);
        $this->assertTrue($expiryEvent->onOneServer);
        Artisan::call('soul:seed-daily-allowances');
        $first = DB::table('daily_cart_allowances')->count();
        Artisan::call('soul:seed-daily-allowances');
        $this->assertSame($first, DB::table('daily_cart_allowances')->count());
        $this->assertArrayNotHasKey('soul:carry-forward-staff-assignments', Artisan::all());
        $this->assertSame(1, StaffAssignment::count());
        Artisan::call('refills:expire-stale');
        Artisan::call('refills:expire-stale');
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 1]);
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 1]);
    }

    public function test_09_user_facing_day_gate_copy_is_casual_and_error_code_is_stable(): void
    {
        $this->actingAs($this->staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertStatus(422)->assertJsonPath('errors.uuid.0', 'Isi ID transaksi dulu ya.');
        $this->actingAs($this->staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_OPEN')
            ->assertJsonPath('message', 'Produksi belum buka absen hari ini, jadi transaksi belum bisa jalan dulu ya.');
        $this->assertSame('Alokasi (Cadangan)', (new AllocationResource(
            new DailyAllocation,
        ))->toArray(request())['flow_label']);
    }

    public function test_10_recipe_gap_is_visible_to_produksi_without_cost_leak(): void
    {
        PermissionMatrix::set(Role::BARISTA, PanelModule::PRODUCTS, ['view']);
        PermissionMatrix::set(Role::BARISTA, PanelModule::PRODUCTION, ['view']);
        $this->actingAs($this->barista)->get(ProductResource::getUrl())->assertOk();
        Livewire::test(ListProducts::class)->assertSee('Resep belum ada')->assertDontSee('HPP');
        $products = $this->actingAs($this->barista, 'sanctum')->getJson('/api/v1/products')->assertOk();
        $row = collect($products->json('data'))->firstWhere('id', $this->product->id);
        $this->assertFalse($row['has_active_recipe']);
        $this->assertArrayNotHasKey('cost_price', $row);

        app(ProductionService::class)->brew($this->kitchen->id, [$this->product->id => 1], $this->barista);
        $this->actingAs($this->barista)->get(ProductionResource::getUrl())->assertOk();
        Livewire::test(ListProductions::class)->assertSee('Resep belum ada')->assertDontSee('Estimasi HPP');
        PermissionMatrix::set(Role::FINANCE, PanelModule::PRODUCTION, ['view']);
        $this->actingAs($this->finance);
        $financeTable = Livewire::test(ListProductions::class)->assertSee('Estimasi HPP');
        $this->assertMatchesRegularExpression('/(?:Rp|IDR)\s*0/u', html_entity_decode(strip_tags($financeTable->html())));

        $material = RawMaterial::create([
            'code' => 'RESEP-T036', 'name' => 'Bahan Resep', 'unit' => 'ml',
            'is_active' => true, 'sort_order' => 1,
        ]);
        app(RecipeService::class)->create($this->product->id, [
            ['raw_material_id' => $material->id, 'qty_per_unit' => 1],
        ], $this->admin);
        $updated = $this->actingAs($this->barista, 'sanctum')->getJson('/api/v1/products')->assertOk();
        $this->assertTrue(collect($updated->json('data'))->firstWhere('id', $this->product->id)['has_active_recipe']);
    }

    public function test_po_receipt_and_debt_payment_journals_balance(): void
    {
        PermissionMatrix::set(Role::FINANCE, PanelModule::PURCHASE_ORDERS, ['view', 'edit']);
        $material = RawMaterial::create([
            'code' => 'JURNAL-T036', 'name' => 'Bahan Jurnal', 'unit' => 'ml',
            'is_active' => true, 'sort_order' => 1,
        ]);
        $supplier = Supplier::create(['code' => 'JURNAL-SUP', 'name' => 'Supplier Jurnal', 'is_active' => true]);
        $orders = app(PurchaseOrderService::class);
        $po = $orders->create($supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $material->id, 'qty_ordered' => 2, 'unit_cost_minor' => 10000],
        ], $this->finance);
        $po = $orders->receive($orders->markOrdered($po, $this->finance), [], $this->finance);
        $cash = CashAccount::query()->whereHas('account', fn ($query) => $query->where('code', '1-1100'))->firstOrFail();
        $payment = $orders->payDebt($po, $this->finance, [
            'uuid' => (string) Str::uuid(), 'paid_on' => today()->toDateString(),
            'cash_account_id' => $cash->id, 'amount_minor' => 5000,
        ]);
        foreach ([['purchase_order', $po->id, 20000], ['purchase_order_payment', $payment->id, 5000]] as [$type, $id, $amount]) {
            $journal = JournalEntry::query()->where('source_type', $type)->where('source_id', $id)
                ->with('lines')->sole();
            $this->assertSame($amount, (int) $journal->lines->sum('debit_minor'));
            $this->assertSame($amount, (int) $journal->lines->sum('credit_minor'));
        }
        $this->assertSame(15000, $po->unpaidMinor());
    }
}
