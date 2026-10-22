<?php

namespace Tests\Feature\Uat;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Exports\RevenueExport;
use App\Filament\Pages\AccountingPeriods;
use App\Filament\Pages\Reports;
use App\Filament\Pages\SetupLokasiRider;
use App\Filament\Resources\CashTransactions\Pages\CreateCashTransaction;
use App\Filament\Resources\DirectSales\Pages\CreateDirectSale;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\PurchaseOrder;
use App\Models\RawMaterial;
use App\Models\RefillRequest;
use App\Models\RiderLocationSetup;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\AccountingPeriodService;
use App\Services\DirectSaleService;
use App\Services\RecipeService;
use App\Services\Reporting\ManagementReportService;
use App\Services\RiderCommissionService;
use App\Services\StockLedgerService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class UatDayRehearsalTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Cart $otherCart;

    private Location $location;

    private Product $product;

    private RawMaterial $material;

    private User $production;

    private User $rider;

    private User $runner;

    private User $finance;

    private User $management;

    private User $admin;

    private int $photoSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow('2026-10-22 10:00:00');
        config(['outbox.publish_mode' => 'queue']);
        $this->seed(AccountSeeder::class);
        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Gladi', 'address' => 'Jl. Uji', 'open_at' => '00:00:00',
            'close_at' => '23:59:59', 'is_active' => true,
        ]);
        $this->cart = Cart::create(['code' => 'UAT-01', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $this->otherCart = Cart::create(['code' => 'UAT-02', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $this->location = Location::create(['name' => 'Titik Gladi', 'lat' => -6.2, 'lng' => 106.8]);
        $this->product = Product::create([
            'code' => 'UAT-A', 'name' => 'Produk A', 'unit' => 'cup', 'is_sellable' => true,
            'sort_order' => 1, 'is_active' => true,
        ]);
        ProductPriceVersion::create([
            'product_id' => $this->product->id, 'cost_price_minor' => 8000,
            'sell_price_minor' => 20000, 'effective_from' => now()->subDay(),
        ]);
        $this->material = RawMaterial::create([
            'code' => 'UAT-BAHAN', 'name' => 'Bahan A', 'unit' => 'ml', 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $this->production = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        $this->rider = User::factory()->role(Role::STAFF)->create();
        $this->runner = User::factory()->role(Role::RIDER)->create();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->management = User::factory()->role(Role::MANAGEMENT)->create();
        RiderLocationSetup::create([
            'rider_id' => $this->rider->id, 'location_id' => $this->location->id,
            'basis' => 'management', 'changed_by' => $this->admin->id,
        ]);
        app(RecipeService::class)->create($this->product->id, [
            ['raw_material_id' => $this->material->id, 'qty_per_unit' => 100],
        ], $this->admin);
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::PURCHASE_IN,
            qty: 5000,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
            rawMaterialId: $this->material->id,
            costMinor: 50000,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PermissionMatrix::forget();
        parent::tearDown();
    }

    private function postAs(User $actor, string $path, array $data = [])
    {
        return $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/'.$path, $data);
    }

    private function stock(string $location, int $id): int
    {
        return app(StockLedgerService::class)->stockFor($location, $id, $this->product->id);
    }

    private function uploadPhoto(string $kind, User $actor): Media
    {
        $id = $this->actingAs($actor, 'sanctum')->post('/api/v1/media/'.$kind, [
            'file' => UploadedFile::fake()->image($kind.Str::uuid().'.jpg', 320 + ++$this->photoSequence, 120),
            'taken_at' => now()->toIso8601String(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $media = Media::findOrFail($id);
        Storage::disk('public')->assertExists($media->path);

        return $media;
    }

    private function accountBalance(string $code): int
    {
        return (int) DB::table('journal_lines as line')->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('account.code', $code)
            ->selectRaw('COALESCE(SUM(CAST(line.debit_minor AS SIGNED) - CAST(line.credit_minor AS SIGNED)), 0) AS balance')
            ->value('balance');
    }

    public function test_one_complete_operating_day_balances_stock_cash_and_journals(): void
    {
        // 3-6: the same phone endpoints used by Produksi and Rider.
        $this->postAs($this->production, 'absen')->assertCreated();
        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->production->id, 'operating_date' => today()->toDateString(),
        ]);
        $this->postAs($this->production, 'absen/open')->assertOk();
        $this->postAs($this->production, 'showcase/brew', [
            'lines' => [['product_id' => $this->product->id, 'qty' => 20]],
        ])->assertOk();
        $this->assertSame(20, $this->stock(StockLedgerService::KITCHEN, $this->kitchen->id));
        $this->assertSame(3000, app(StockLedgerService::class)->rawMaterialStockFor(
            StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $this->material->id
        ));
        $productionJournal = JournalEntry::query()->where('source_type', 'production')->firstOrFail();
        $this->assertSame($productionJournal->lines()->sum('debit_minor'), $productionJournal->lines()->sum('credit_minor'));
        $this->postAs($this->rider, 'absen')->assertCreated();
        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->rider->id, 'operating_date' => today()->toDateString(),
        ]);
        $this->postAs($this->production, 'showcase/hand-to-cart', [
            'cart_id' => $this->cart->id, 'staff_id' => $this->rider->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 10]],
        ])->assertCreated();
        $this->assertSame(10, $this->stock(StockLedgerService::KITCHEN, $this->kitchen->id));
        $this->assertSame(10, $this->stock(StockLedgerService::CART, $this->cart->id));
        $this->assertDatabaseHas('staff_assignments', [
            'user_id' => $this->rider->id, 'cart_id' => $this->cart->id,
            'location_id' => $this->location->id, 'operating_date' => today()->toDateString(),
        ]);
        $this->assertDatabaseHas('daily_cart_allowances', [
            'cart_id' => $this->cart->id, 'amount_minor' => config('soul.daily_cart_allowance'),
        ]);

        // 7-10: a partial approval must retain its reason and end with a signed handover.
        $refillId = $this->postAs($this->rider, 'refills', [
            'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
            'evidence_media_id' => $this->uploadPhoto('evidence', $this->rider)->id,
            'lines' => [['product_id' => $this->product->id, 'qty_requested' => 5]],
        ])->assertCreated()->json('data.id');
        $refill = RefillRequest::findOrFail($refillId);
        $this->assertSame($this->rider->id, $refill->evidencePhoto->uploaded_by);
        $lineId = $refill->lines()->firstOrFail()->id;
        $this->assertSame('SUBMITTED', $refill->status->value);
        $this->postAs($this->finance, "refills/{$refillId}/approve", [
            'version' => $refill->version, 'partial_reason' => 'Stok hanya cukup empat',
            'lines' => [['line_id' => $lineId, 'qty_approved' => 4]],
        ])->assertOk();
        $this->assertSame('APPROVED', $refill->fresh()->status->value);
        $this->assertSame('Stok hanya cukup empat', $refill->fresh()->decision_reason);
        $this->postAs($this->production, "refills/{$refillId}/start-preparing")->assertOk();
        $this->assertSame('PREPARING', $refill->fresh()->status->value);
        $this->postAs($this->production, "refills/{$refillId}/ready", [
            'lines' => [['line_id' => $lineId, 'qty_prepared' => 4]],
        ])->assertOk();
        $this->assertSame('READY_TO_PICK', $refill->fresh()->status->value);
        $this->postAs($this->runner, "refills/{$refillId}/claim")->assertOk();
        $this->assertSame('PICKED_UP', $refill->fresh()->status->value);
        $this->actingAs($this->runner, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post("/api/v1/refills/{$refillId}/deliver", [
                'handover_media_id' => $this->uploadPhoto('handover', $this->runner)->id,
                'lines' => json_encode([['line_id' => $lineId, 'qty_received' => 4]]),
                'signature_method' => 'staff_signature', 'stroke_count' => 3,
                'signature' => UploadedFile::fake()->image('ttd.png', 320, 120),
            ], ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertContains($refill->fresh()->status->value, ['DELIVERED', 'CLOSED']);
        $this->assertNotNull($refill->fresh()->signature_id);
        $this->assertSame($this->runner->id, $refill->fresh()->handoverPhoto->uploaded_by);
        $this->assertSame(14, $this->stock(StockLedgerService::CART, $this->cart->id));

        // 11: office revenue is its own channel, using a different cart.
        app(StockLedgerService::class)->post(StockLedgerService::CART, $this->otherCart->id,
            $this->product->id, MovementType::ALLOCATION_IN, 5, $this->admin->id, $this->kitchen->id);
        Livewire::actingAs($this->finance)->test(CreateDirectSale::class)->fillForm([
            'cart_id' => $this->otherCart->id, 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
        ])->call('create')->assertHasNoFormErrors();
        $this->assertSame(3, $this->stock(StockLedgerService::CART, $this->otherCart->id));
        $this->assertSame(-40000, $this->accountBalance('4-1200'));

        // 12-15: 14 in, 3 returned, 1 wasted leaves 10 Rider sales.
        $this->postAs($this->rider, 'me/closing', [
            'lines' => [['product_id' => $this->product->id, 'qty_remaining' => 3, 'qty_wasted' => 1]],
            'qris' => 100000, 'transfer' => 0, 'gps_lat' => -6.2, 'gps_lng' => 106.8,
        ])->assertCreated()->assertJsonPath('data.lines.0.qty_sold', 10)
            ->assertJsonPath('data.total_minor', 200000)
            ->assertJsonPath('data.cash_minor', 100000);
        $this->postAs($this->runner, 'showcase/close-out', [
            'kitchen_id' => $this->kitchen->id,
            'cart_id' => $this->cart->id,
            'returned' => [['product_id' => $this->product->id, 'qty' => 3]],
        ])->assertOk();
        $this->assertDatabaseCount('cart_closeout_variances', 0);
        $this->assertSame(0, $this->stock(StockLedgerService::CART, $this->cart->id));
        $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/settlements/draft/'.$this->cart->id)->assertOk();
        $settlementId = $this->postAs($this->finance, 'settlements', [
            'cart_id' => $this->cart->id, 'cash' => 100000, 'qris' => 100000, 'transfer' => 0,
        ])->assertCreated()->json('data.id');
        $this->postAs($this->finance, "settlements/{$settlementId}/approve", ['lines' => []])->assertOk();
        $settlement = Settlement::findOrFail($settlementId);
        $this->assertSame('RECONCILED', $settlement->status);
        $this->assertSame(0, $settlement->variance_minor);
        $this->assertSame(100000, $this->accountBalance('1-1200'));
        $this->assertSame(100000, $this->accountBalance('1-1400'));
        $this->assertSame(-200000, $this->accountBalance('4-1100'));
        $this->assertGreaterThan(0, JournalEntry::query()->where('purpose', 'SALE_COGS')->count());
        $this->assertTrue(app(RiderCommissionService::class)->day($this->rider->id, today()->toDateString())['locked']);

        // 16-17: the clearing receipt and cash bank deposit zero their clearing accounts.
        $bankId = Account::query()->where('code', '1-1300')->value('id');
        $this->postAs($this->finance, 'clearing-receipts', [
            'uuid' => (string) Str::uuid(), 'received_on' => today()->toDateString(),
            'amount_minor' => 99300, 'mdr_minor' => 700, 'bank_account_id' => $bankId,
            'sale_dates' => [today()->toDateString()],
        ])->assertCreated();
        $this->assertSame(0, $this->accountBalance('1-1400'));
        $this->assertSame(99300, $this->accountBalance('1-1300'));
        $this->assertSame(700, $this->accountBalance('6-1990'));
        $bankInput = [
            'uuid' => (string) Str::uuid(), 'date' => today()->toDateString(),
            'cash_category_id' => DB::table('cash_categories')->where('name', 'Setor tunai ke bank')->value('id'),
            'cash_account_id' => CashAccount::query()->where('name', 'Kas Setoran')->value('id'),
            'amount_minor' => 100000, 'description' => 'Setor tunai harian',
        ];
        Livewire::actingAs($this->finance)->test(CreateCashTransaction::class)
            ->fillForm($bankInput)->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('cash_transactions', ['uuid' => $bankInput['uuid'], 'status' => 'POSTED']);
        $this->assertSame(0, $this->accountBalance('1-1200'));
        $this->assertSame(199300, $this->accountBalance('1-1300'));

        // 18: a documented galon expense is entered through the same CMS form.
        Storage::fake('local');
        Storage::disk('local')->put('cash-evidence/galon.jpg', UploadedFile::fake()->image('galon.jpg')->getContent());
        $galon = CashCategory::create([
            'name' => 'Galon', 'direction' => 'OUT',
            'account_id' => Account::query()->where('code', '6-1400')->value('id'), 'is_active' => true,
        ]);
        $expenseInput = [
            'uuid' => (string) Str::uuid(), 'date' => today()->toDateString(),
            'cash_category_id' => $galon->id,
            'cash_account_id' => CashAccount::query()->where('name', 'Bank')->value('id'),
            'amount_minor' => 25000, 'description' => 'Beli galon untuk dapur',
            'evidence_path' => ['cash-evidence/galon.jpg'],
        ];
        Livewire::actingAs($this->finance)->test(CreateCashTransaction::class)
            ->fillForm($expenseInput)->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('cash_transactions', [
            'uuid' => $expenseInput['uuid'], 'evidence_path' => 'cash-evidence/galon.jpg',
        ]);
        $this->assertSame(174300, $this->accountBalance('1-1300'));
        $this->assertSame(25000, $this->accountBalance('6-1400'));
        $this->assertTrue(JournalEntry::query()->where('source_type', 'cash_transaction')
            ->where('source_id', DB::table('cash_transactions')->where('uuid', $expenseInput['uuid'])->value('id'))->exists());

        // 19: PO is created and operated through its CMS actions.
        $supplier = Supplier::create(['code' => 'UAT-SUP', 'name' => 'Pemasok Gladi', 'is_active' => true]);
        Livewire::actingAs($this->finance)->test(CreatePurchaseOrder::class)->fillForm([
            'supplier_id' => $supplier->id, 'kitchen_id' => $this->kitchen->id,
            'lines' => [['raw_material_id' => $this->material->id, 'qty_ordered' => 1000, 'unit_cost_minor' => 10]],
        ])->call('create')->assertHasNoFormErrors();
        $po = PurchaseOrder::query()->latest('id')->firstOrFail();
        Livewire::actingAs($this->finance)->test(ListPurchaseOrders::class)
            ->callTableAction('markOrdered', $po)->assertHasNoTableActionErrors();
        Livewire::actingAs($this->finance)->test(ListPurchaseOrders::class)
            ->callTableAction('receive', $po->fresh(), data: [
                'payment_method' => 'CREDIT',
                'lines' => [['raw_material_id' => $this->material->id, 'qty_received' => 1000]],
            ])->assertHasNoTableActionErrors();
        $this->assertSame(4000, app(StockLedgerService::class)->rawMaterialStockFor(
            StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $this->material->id
        ));
        $this->assertSame(10000, $po->fresh()->unpaidMinor());
        $this->assertSame(-10000, $this->accountBalance('2-1400'));
        Livewire::actingAs($this->finance)->test(ListPurchaseOrders::class)->filterTable('open', false)
            ->callTableAction('payDebt', $po->fresh(), data: [
                'uuid' => (string) Str::uuid(), 'paid_on' => today()->toDateString(),
                'cash_account_id' => CashAccount::query()->where('name', 'Bank')->value('id'),
                'amount_minor' => 10000,
            ])->assertHasNoTableActionErrors();
        $this->assertSame(0, $po->fresh()->unpaidMinor());
        $this->assertSame(0, $this->accountBalance('2-1400'));

        // 20-24: the same reports and access service behind the CMS screens.
        $ledgerRevenue = -$this->accountBalance('4-1100') - $this->accountBalance('4-1200');
        $export = (new RevenueExport(today(), today()))->sheets()[0]->array();
        $this->assertSame($ledgerRevenue, end($export)[6]);
        Livewire::actingAs($this->management)->test(Reports::class)
            ->fillForm(['from' => today()->toDateString(), 'to' => today()->toDateString()])
            ->call('exportRevenue')
            ->assertFileDownloaded('pendapatan_'.today()->toDateString().'_'.today()->toDateString().'.xlsx');
        $report = app(ManagementReportService::class)->build(today(), today());
        $this->assertSame($ledgerRevenue, $report['summary']['revenue_minor']);
        $this->assertSame([200000, 80000, 120000],
            array_slice($report['sections']['margin_gerobak']['rows'][0], 1, 3));
        $readiness = app(AccountingPeriodService::class)->closeReadiness(2026, 10);
        $this->assertSame([], array_values(array_filter($readiness, fn (array $row): bool => $row['status'] === 'blocking')));
        $this->actingAs($this->management, 'web')->get(AccountingPeriods::getUrl())->assertOk();
        Livewire::actingAs($this->management)->test(AccountingPeriods::class)
            ->call('closeSelected')->assertHasNoErrors();
        $this->assertDatabaseHas('accounting_periods', ['year' => 2026, 'month' => 10, 'status' => 'CLOSED']);
        foreach (['staff_attendance_window.opened' => $this->production,
            'central_stock.brew' => $this->production, 'central_stock.hand_to_cart' => $this->production,
            'central_stock.close_out' => $this->runner,
            'refill.start_preparing' => $this->production, 'refill.ready' => $this->production,
            'direct_sale.created' => $this->finance, 'rider_closing.recorded' => $this->rider,
            'settlement.approved' => $this->finance, 'clearing_receipt.created' => $this->finance,
            'cash_transaction.created' => $this->finance, 'purchase_order.created' => $this->finance,
            'purchase_order.received' => $this->finance, 'purchase_order.paid' => $this->finance,
            'accounting_period.closed' => $this->management] as $action => $actor) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->where('actor_id', $actor->id)->exists(), $action);
        }
        Livewire::actingAs($this->admin)->test(EditUser::class, ['record' => $this->production->id])
            ->set('data.access_grants.rider_location_setup', 'edit')
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue(PermissionMatrix::can($this->production, PanelModule::RIDER_LOCATION_SETUP, 'edit'));
        Livewire::actingAs($this->production)->test(SetupLokasiRider::class)
            ->assertTableActionVisible('setup', $this->rider);
        Livewire::actingAs($this->admin)->test(EditUser::class, ['record' => $this->production->id])
            ->set('data.access_grants.rider_location_setup', 'view')
            ->call('save')->assertHasNoFormErrors();
        $this->assertFalse(PermissionMatrix::can($this->production, PanelModule::RIDER_LOCATION_SETUP, 'edit'));
        Livewire::actingAs($this->production)->test(SetupLokasiRider::class)
            ->assertTableActionHidden('setup', $this->rider);
        $this->assertSame((int) DB::table('journal_lines')->sum('debit_minor'),
            (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    public function test_office_sale_from_rider_cart_is_excluded_before_closing(): void
    {
        $this->postAs($this->production, 'absen')->assertCreated();
        $this->postAs($this->production, 'absen/open')->assertOk();
        $this->postAs($this->rider, 'absen')->assertCreated();
        StaffAssignment::create([
            'source' => 'hand_to_cart', 'user_id' => $this->rider->id, 'cart_id' => $this->cart->id,
            'location_id' => $this->location->id, 'operating_date' => today(),
            'assigned_by' => $this->admin->id, 'kitchen_id' => $this->kitchen->id,
        ]);
        app(StockLedgerService::class)->post(StockLedgerService::CART, $this->cart->id,
            $this->product->id, MovementType::ALLOCATION_IN, 14, $this->admin->id, $this->kitchen->id);
        app(DirectSaleService::class)->record($this->finance, $this->cart->id, [
            ['product_id' => $this->product->id, 'qty' => 2],
        ]);
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/closing/draft')
            ->assertOk()->assertJsonPath('data.lines.0.qty_office_sold', 2);
        $this->postAs($this->rider, 'me/closing', [
            'lines' => [['product_id' => $this->product->id, 'qty_remaining' => 3, 'qty_wasted' => 1]],
            'qris' => 100000, 'transfer' => 0, 'gps_lat' => -6.2, 'gps_lng' => 106.8,
        ])->assertCreated()->assertJsonPath('data.lines.0.qty_sold', 8)
            ->assertJsonPath('data.cash_minor', 60000);
        $this->assertSame(3, $this->stock(StockLedgerService::CART, $this->cart->id));
    }

    public function test_finance_can_perform_the_same_kitchen_flow_with_an_explicit_kitchen(): void
    {
        $this->postAs($this->production, 'absen')->assertCreated();
        $this->postAs($this->production, 'absen/open')->assertOk();
        $this->postAs($this->rider, 'absen')->assertCreated();
        $this->postAs($this->production, 'showcase/brew', [
            'lines' => [['product_id' => $this->product->id, 'qty' => 10]],
        ])->assertOk();
        $this->postAs($this->production, 'showcase/hand-to-cart', [
            'cart_id' => $this->cart->id, 'staff_id' => $this->rider->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
        ])->assertCreated();
        $baristaProduction = JournalEntry::query()->where('source_type', 'production')->latest('id')->firstOrFail();
        $baristaJournal = $baristaProduction->lines()->orderBy('account_id')->get()
            ->map(fn ($line): array => [$line->account_id, $line->debit_minor, $line->credit_minor])->all();

        $otherRider = User::factory()->role(Role::STAFF)->create();
        RiderLocationSetup::create([
            'rider_id' => $otherRider->id, 'location_id' => $this->location->id,
            'basis' => 'management', 'changed_by' => $this->admin->id,
        ]);
        $this->postAs($otherRider, 'absen')->assertCreated();
        $this->postAs($this->finance, 'showcase/brew', [
            'kitchen_id' => $this->kitchen->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 10]],
        ])->assertOk();
        $this->postAs($this->finance, 'showcase/hand-to-cart', [
            'kitchen_id' => $this->kitchen->id, 'cart_id' => $this->otherCart->id,
            'staff_id' => $otherRider->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
        ])->assertCreated();
        $financeProduction = JournalEntry::query()->where('source_type', 'production')->latest('id')->firstOrFail();
        $financeJournal = $financeProduction->lines()->orderBy('account_id')->get()
            ->map(fn ($line): array => [$line->account_id, $line->debit_minor, $line->credit_minor])->all();
        $this->assertSame($baristaJournal, $financeJournal);
        $this->assertSame(5, $this->stock(StockLedgerService::CART, $this->cart->id));
        $this->assertSame(5, $this->stock(StockLedgerService::CART, $this->otherCart->id));
        $this->assertSame(10, $this->stock(StockLedgerService::KITCHEN, $this->kitchen->id));
        $this->assertSame(3000, app(StockLedgerService::class)->rawMaterialStockFor(
            StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $this->material->id
        ));
        $this->finishKitchenFlow($this->production, $this->cart, $this->rider);
        $this->finishKitchenFlow($this->finance, $this->otherCart, $otherRider);
        $this->assertSame(4, $this->stock(StockLedgerService::CART, $this->cart->id));
        $this->assertSame(4, $this->stock(StockLedgerService::CART, $this->otherCart->id));
        $this->assertSame((int) DB::table('journal_lines')->sum('debit_minor'),
            (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    private function finishKitchenFlow(User $actor, Cart $cart, User $staff): void
    {
        $kitchenContext = $actor->role === Role::FINANCE ? ['kitchen_id' => $this->kitchen->id] : [];
        $refillId = $this->postAs($staff, 'refills', [
            'uuid' => (string) Str::uuid(), 'cart_id' => $cart->id,
            'evidence_media_id' => $this->uploadPhoto('evidence', $staff)->id,
            'lines' => [['product_id' => $this->product->id, 'qty_requested' => 1]],
        ])->assertCreated()->json('data.id');
        $refill = RefillRequest::findOrFail($refillId);
        $lineId = $refill->lines()->firstOrFail()->id;
        $this->postAs($this->finance, "refills/{$refillId}/approve", [
            'version' => $refill->version,
            'lines' => [['line_id' => $lineId, 'qty_approved' => 1]],
        ])->assertOk();
        $this->postAs($actor, "refills/{$refillId}/start-preparing", $kitchenContext)->assertOk();
        $this->postAs($actor, "refills/{$refillId}/ready", $kitchenContext + [
            'lines' => [['line_id' => $lineId, 'qty_prepared' => 1]],
        ])->assertOk();
        $this->postAs($this->runner, "refills/{$refillId}/claim")->assertOk();
        $this->actingAs($this->runner, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post("/api/v1/refills/{$refillId}/deliver", [
                'handover_media_id' => $this->uploadPhoto('handover', $this->runner)->id,
                'lines' => json_encode([['line_id' => $lineId, 'qty_received' => 1]]),
                'stroke_count' => 0,
            ], ['Accept' => 'application/json'])->assertSuccessful();
        $closeOutActor = $actor->role === Role::BARISTA ? $this->runner : $actor;
        $this->postAs($closeOutActor, 'showcase/close-out', ['kitchen_id' => $this->kitchen->id] + [
            'cart_id' => $cart->id,
            'returned' => [['product_id' => $this->product->id, 'qty' => 1]],
            'rejected' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertOk();
    }
}
