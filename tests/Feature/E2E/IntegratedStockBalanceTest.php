<?php

namespace Tests\Feature\E2E;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\CentralStock;
use App\Filament\Pages\StockOpnameEntry;
use App\Filament\Resources\DirectSales\Pages\CreateDirectSale;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Models\AppNotification;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\DeliveryIncident;
use App\Models\Location;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\PurchaseOrder;
use App\Models\RawMaterial;
use App\Models\RefillRequest;
use App\Models\RiderClosing;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\StockLedger;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\AttendanceService;
use App\Services\RecipeService;
use App\Services\Reporting\StockOverviewService;
use App\Services\StockLedgerService;
use App\Services\StockReconciliationService;
use Database\Seeders\AccountSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class IntegratedStockBalanceTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Product $product;

    private User $finance;

    private User $production;

    private User $rider;

    private User $runner;

    private User $manager;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');
        config(['outbox.publish_mode' => 'queue']);
        $this->seed(AccountSeeder::class);
        $this->kitchen = CentralKitchen::create(['name' => 'Dapur Integrated', 'address' => 'Jl. Uji',
            'open_at' => '00:00', 'close_at' => '23:59', 'is_active' => true]);
        $this->cart = Cart::create(['code' => 'T075', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $this->product = Product::create(['code' => 'T075-CUP', 'name' => 'Kopi Integrated', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        ProductPriceVersion::create(['product_id' => $this->product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->manager = User::factory()->role(Role::FINANCE)->create(['is_management' => true]);
        $this->administrator = User::factory()->role(Role::ADMINISTRATOR)->create();
        PermissionMatrix::set(Role::FINANCE, PanelModule::CENTRAL_STOCK, ['view', 'edit']);
        $this->production = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        $this->rider = User::factory()->role(Role::STAFF)->create();
        $this->runner = User::factory()->role(Role::RIDER)->create();
        $location = Location::create(['name' => 'Titik T075', 'lat' => -6.18, 'lng' => 106.88]);
        StaffAssignment::create(['source' => 'hand_to_cart', 'user_id' => $this->rider->id,
            'cart_id' => $this->cart->id, 'location_id' => $location->id,
            'operating_date' => today()->toDateString(), 'assigned_by' => $this->finance->id,
            'kitchen_id' => $this->kitchen->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function evidence(string $kind, User $actor): Media
    {
        return Media::create(['kind' => $kind, 'path' => $kind.'/'.Str::uuid().'.jpg',
            'mime' => 'image/jpeg', 'bytes' => 1024, 'sha256' => hash('sha256', $this->key()),
            'exif_taken_at' => now(), 'uploaded_by' => $actor->id]);
    }

    private function assertIntegrated(string $step): void
    {
        $findings = app(StockReconciliationService::class)->findings();
        $this->assertSame([], $findings, $step.': '.json_encode($findings));

        $snapshot = app(StockOverviewService::class)->snapshot();
        foreach ($snapshot['kitchens'] as $row) {
            $kitchen = $row['kitchen'];
            $barista = User::query()->where('role', Role::BARISTA)->where('kitchen_id', $kitchen->id)->firstOrFail();
            foreach (['/api/v1/showcase/stock', '/api/v1/kitchen/stock'] as $endpoint) {
                $stock = $this->actingAs($barista, 'sanctum')->getJson($endpoint)->assertOk()->json('data');
                foreach ($snapshot['products'] as $product) {
                    $this->assertSame((int) $row['qty'][$product->id],
                        (int) (collect($stock)->firstWhere('product_id', $product->id)['qty'] ?? 0),
                        $step.': '.$endpoint.' '.$kitchen->name.' / '.$product->name);
                }
            }
        }

        foreach ($snapshot['carts'] as $row) {
            $cart = $row['cart'];
            $kitchenId = $cart->kitchen_id;
            $assignment = StaffAssignment::query()->where('cart_id', $cart->id)
                ->whereDate('operating_date', today()->toDateString())
                ->where('source', 'hand_to_cart')->whereNull('superseded_at')->latest('id')->first();
            if ($assignment !== null) {
                $rider = User::findOrFail($assignment->user_id);
                $riderStock = $this->actingAs($rider, 'sanctum')
                    ->getJson('/api/v1/me/stock')->assertOk()->json('data');
                foreach ($snapshot['products'] as $product) {
                    $this->assertSame((int) $row['qty'][$product->id],
                        (int) (collect($riderStock)->firstWhere('product_id', $product->id)['qty'] ?? 0),
                        $step.': Rider '.$cart->code.' / '.$product->name);
                }
                $closingResponse = $this->actingAs($rider, 'sanctum')->getJson('/api/v1/me/closing/draft');
                foreach ($snapshot['products'] as $product) {
                    $incoming = (int) DB::table('stock_ledger')->where('location_type', 'cart')
                        ->where('location_id', $cart->id)->where('product_id', $product->id)
                        ->whereIn('movement_type', ['ALLOCATION_IN', 'REFILL_IN'])->sum('qty_delta');
                    if ($closingResponse->status() === 200) {
                        $lines = $closingResponse->assertOk()->json('data.lines');
                        $this->assertSame($incoming,
                            (int) (collect($lines)->firstWhere('product_id', $product->id)['qty_in'] ?? 0),
                            $step.': Closing '.$cart->code.' / '.$product->name);
                    } else {
                        $this->assertSame(0, $incoming, $step);
                        $closingResponse->assertStatus(422);
                    }
                }
            }
            $runnerDraft = $this->actingAs($this->runner, 'sanctum')
                ->getJson('/api/v1/showcase/close-out/draft/'.$cart->id.'?kitchen_id='.$kitchenId)
                ->assertOk()->json('data');
            $financeDraft = $this->actingAs($this->finance, 'sanctum')
                ->getJson('/api/v1/settlements/draft/'.$cart->id);
            $hasClosing = RiderClosing::query()->where('cart_id', $cart->id)->exists();
            if (! $hasClosing) {
                $financeDraft->assertStatus(409)->assertJsonPath('code', 'CLOSING_REQUIRED');
            } else {
                $financeDraft->assertOk();
            }
            foreach ($snapshot['products'] as $product) {
                $cartQty = (int) $row['qty'][$product->id];
                $runnerLine = collect($runnerDraft['lines'])->firstWhere('product_id', $product->id);
                if ($runnerLine !== null) {
                    $this->assertSame(min((int) $runnerLine['qty_remaining'], $cartQty),
                        (int) $runnerLine['qty_to_return'], $step.': Runner '.$cart->code.' / '.$product->name);
                }
                if ($hasClosing) {
                    $line = collect($financeDraft->json('data.lines'))->firstWhere('product_id', $product->id);
                    $this->assertSame($cartQty, (int) ($line['qty_remaining'] ?? 0),
                        $step.': Finance '.$cart->code.' / '.$product->name);
                }
            }
        }

        foreach ([$this->finance, $this->manager, $this->administrator] as $viewer) {
            $response = $this->actingAs($viewer, 'web')->get(CentralStock::getUrl())->assertOk()
                ->assertSee('Integritas Stok');
            $this->assertCmsStockTables($response->getContent(), $snapshot, $step);
        }
        foreach ($snapshot['products'] as $product) {
            $kitchenQty = (int) $snapshot['kitchens']->sum(fn (array $row): int => $row['qty'][$product->id]);
            $cartQty = (int) $snapshot['carts']->sum(fn (array $row): int => $row['qty'][$product->id]);
            $this->assertSame($kitchenQty + $cartQty, $snapshot['grand_totals'][$product->id], $step);
        }
    }

    /** Assert the rendered CMS cells, including raw material stock, for each viewer. */
    private function assertCmsStockTables(string $html, array $snapshot, string $step): void
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $tables = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " bsi-grid ")]');

        $check = function (int $tableIndex, $rows, $items, string $rowKey) use ($tables, $xpath, $step): void {
            $table = $tables->item($tableIndex);
            $this->assertNotNull($table, $step.': CMS table '.$tableIndex);
            $renderedRows = $xpath->query('./tbody/tr', $table);
            $this->assertCount($rows->count(), $renderedRows, $step.': CMS row count');
            foreach ($rows->values() as $index => $row) {
                $cells = $xpath->query('./td', $renderedRows->item($index));
                $label = $row[$rowKey]->name ?? $row[$rowKey]->code;
                $this->assertStringStartsWith($label, trim($cells->item(0)->textContent), $step);
                foreach ($items->values() as $column => $item) {
                    $shown = trim($cells->item($column + 1)->textContent);
                    $this->assertSame(number_format((int) $row['qty'][$item->id], 0, ',', '.'),
                        $shown, $step.': CMS '.$label.' / '.$item->name);
                }
            }
        };

        $check(0, $snapshot['kitchens'], $snapshot['products'], 'kitchen');
        $check(1, $snapshot['carts'], $snapshot['products'], 'cart');
        if ($snapshot['raw_materials']->isNotEmpty()) {
            $check(2, $snapshot['raw_material_rows'], $snapshot['raw_materials'], 'kitchen');
        }
    }

    public function test_operating_day_keeps_every_stock_projection_and_inventory_journal_balanced(): void
    {
        $raw = RawMaterial::create(['code' => 'T075-RAW', 'name' => 'Bahan Integrated',
            'unit' => 'pcs', 'is_active' => true, 'sort_order' => 1]);
        $supplier = Supplier::create(['code' => 'T075-SUP', 'name' => 'Pemasok Integrated', 'is_active' => true]);
        $secondProduct = Product::create(['code' => 'T075-CUP-B', 'name' => 'Kopi Kedua',
            'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 2, 'is_active' => true]);
        ProductPriceVersion::create(['product_id' => $secondProduct->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);
        $secondKitchen = CentralKitchen::create(['name' => 'Dapur Kedua', 'address' => 'Jl. Uji Kedua',
            'open_at' => '00:00', 'close_at' => '23:59', 'is_active' => true]);
        $secondBarista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $secondKitchen->id]);
        app(RecipeService::class)->create($this->product->id,
            [['raw_material_id' => $raw->id, 'qty_per_unit' => 1]], $this->production);
        app(RecipeService::class)->create($secondProduct->id,
            [['raw_material_id' => $raw->id, 'qty_per_unit' => 1]], $this->production);
        $this->assertIntegrated('setup');

        Livewire::actingAs($this->finance)->test(CreatePurchaseOrder::class)
            ->fillForm(['supplier_id' => $supplier->id, 'kitchen_id' => $this->kitchen->id,
                'lines' => [['raw_material_id' => $raw->id, 'qty_ordered' => 150,
                    'unit_cost_minor' => 1000]]])->call('create')->assertHasNoFormErrors();
        $po = PurchaseOrder::query()->sole();
        $this->assertIntegrated('PO draft');
        Livewire::actingAs($this->finance)->test(ListPurchaseOrders::class)
            ->callTableAction('markOrdered', $po);
        $this->assertIntegrated('PO ordered');
        Livewire::actingAs($this->finance)->test(ListPurchaseOrders::class)
            ->callTableAction('receive', $po->fresh(), data: ['payment_method' => 'CREDIT',
                'lines' => [['raw_material_id' => $raw->id, 'qty_received' => 150]]])
            ->assertHasNoTableActionErrors();
        $this->assertIntegrated('PO received');

        Livewire::actingAs($this->finance)->test(CreatePurchaseOrder::class)
            ->fillForm(['supplier_id' => $supplier->id, 'kitchen_id' => $secondKitchen->id,
                'lines' => [['raw_material_id' => $raw->id, 'qty_ordered' => 3,
                    'unit_cost_minor' => 1000]]])->call('create')->assertHasNoFormErrors();
        $secondPo = PurchaseOrder::query()->latest('id')->firstOrFail();
        $this->assertIntegrated('PO dapur kedua draft');
        Livewire::actingAs($this->finance)->test(ListPurchaseOrders::class)
            ->callTableAction('markOrdered', $secondPo);
        $this->assertIntegrated('PO dapur kedua ordered');
        Livewire::actingAs($this->finance)->test(ListPurchaseOrders::class)
            ->callTableAction('receive', $secondPo->fresh(), data: ['payment_method' => 'CREDIT',
                'lines' => [['raw_material_id' => $raw->id, 'qty_received' => 3]]])
            ->assertHasNoTableActionErrors();
        $this->assertIntegrated('PO dapur kedua received');

        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->production);
        $attendance->clockIn($secondBarista);
        $attendance->openStaffWindow($this->production);
        $attendance->clockIn($this->rider);
        $this->actingAs($this->production, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/brew', ['lines' => [['product_id' => $this->product->id, 'qty' => 120]]])
            ->assertOk();
        $this->assertIntegrated('Brew 120');
        $this->actingAs($this->production, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/brew', ['lines' => [['product_id' => $secondProduct->id, 'qty' => 3]]])
            ->assertOk();
        $this->assertIntegrated('Brew produk kedua');
        $this->actingAs($secondBarista, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/brew', ['lines' => [['product_id' => $secondProduct->id, 'qty' => 2]]])
            ->assertOk();
        $this->assertIntegrated('Brew dapur kedua');
        $this->actingAs($this->production, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', ['cart_id' => $this->cart->id,
                'staff_id' => $this->rider->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 20]]])->assertCreated();
        $this->assertIntegrated('Add Stock');

        $secondCart = Cart::create(['code' => 'T075-B', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $secondRider = User::factory()->role(Role::STAFF)->create();
        $location = Location::query()->sole();
        StaffAssignment::create(['source' => 'hand_to_cart', 'user_id' => $secondRider->id,
            'cart_id' => $secondCart->id, 'location_id' => $location->id,
            'operating_date' => today()->toDateString(), 'assigned_by' => $this->finance->id,
            'kitchen_id' => $this->kitchen->id]);
        $attendance->clockIn($secondRider);
        $this->actingAs($this->production, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/allocations', ['operating_date' => today()->toDateString(),
                'cart_id' => $secondCart->id, 'staff_id' => $secondRider->id, 'location_id' => $location->id,
                'lines' => [['product_id' => $this->product->id, 'qty_issued' => 5],
                    ['product_id' => $secondProduct->id, 'qty_issued' => 1]]])->assertCreated();
        $this->assertIntegrated('Alokasi');
        $secondStock = $this->actingAs($secondRider, 'sanctum')->getJson('/api/v1/me/stock')->assertOk()->json('data');
        $this->assertSame(5, collect($secondStock)->firstWhere('product_id', $this->product->id)['qty']);

        $refillId = $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/refills', ['uuid' => $this->key(), 'cart_id' => $this->cart->id,
                'evidence_media_id' => $this->evidence('evidence', $this->rider)->id,
                'lines' => [['product_id' => $this->product->id, 'qty_requested' => 5]]])
            ->assertCreated()->json('data.id');
        $refill = RefillRequest::findOrFail($refillId);
        $lineId = $refill->lines()->sole()->id;
        $this->assertIntegrated('Refill request');
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/refills/'.$refillId.'/approve', ['version' => $refill->version,
                'lines' => [['line_id' => $lineId, 'qty_approved' => 5]]])->assertOk();
        $this->assertIntegrated('Refill approve');
        $this->actingAs($this->production, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/refills/'.$refillId.'/start-preparing')->assertOk();
        $this->assertIntegrated('Refill prepare');
        $this->actingAs($this->production, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/refills/'.$refillId.'/ready', [
                'lines' => [['line_id' => $lineId, 'qty_prepared' => 5]]])->assertOk();
        $this->assertIntegrated('Refill ready');
        $this->actingAs($this->runner, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/refills/'.$refillId.'/claim')->assertOk();
        $this->assertIntegrated('Refill claim');
        $this->actingAs($this->runner, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/refills/'.$refillId.'/incident', ['uuid' => $this->key(),
                'photo' => UploadedFile::fake()->image('insiden.jpg', 640, 480),
                'photo_taken_at' => now()->toIso8601String(),
                'lines' => json_encode([['line_id' => $lineId, 'qty_damaged' => 1]]),
                'note' => 'Satu cup pecah di perjalanan.'])->assertCreated();
        $this->assertIntegrated('Insiden pengantaran');
        $incident = DeliveryIncident::query()->sole();
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/incidents/'.$incident->id.'/resolve', [
                'mode' => 'partial', 'note' => 'Satu cup dipisahkan, sisanya aman.'])->assertOk();
        $this->assertIntegrated('Insiden resolved');
        $this->actingAs($this->runner, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->post('/api/v1/refills/'.$refillId.'/deliver', [
                'handover_media_id' => $this->evidence('handover', $this->runner)->id,
                'lines' => json_encode([['line_id' => $lineId, 'qty_received' => 4]]),
                'signature_method' => 'staff_signature', 'stroke_count' => 3,
                'signature' => UploadedFile::fake()->image('tanda-tangan.png', 320, 120),
            ], ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertIntegrated('Refill delivered');

        $saleId = $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/sales', ['uuid' => $this->key(), 'cart_id' => $this->cart->id,
                'payment_method' => 'cash', 'lines' => [['product_id' => $this->product->id, 'qty' => 2]]])
            ->assertCreated()->json('data.id');
        $this->assertIntegrated('Sale');
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/sales/'.$saleId.'/void', ['reason' => 'Salah input'])->assertOk();
        $this->assertIntegrated('Sale void');

        Livewire::actingAs($this->finance)->test(CreateDirectSale::class)
            ->fillForm(['cart_id' => $this->cart->id, 'payment_method' => 'cash',
                'lines' => [['product_id' => $this->product->id, 'qty' => 2]]])
            ->call('create')->assertHasNoFormErrors();
        $this->assertIntegrated('Penjualan Langsung');

        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/me/closing', ['lines' => [['product_id' => $this->product->id,
                'qty_remaining' => 19, 'qty_wasted' => 1]], 'qris' => 0, 'transfer' => 0,
                'gps_lat' => -6.18, 'gps_lng' => 106.88])->assertCreated();
        $this->assertIntegrated('Closing');
        $closing = RiderClosing::query()->sole();
        $this->actingAs($this->runner, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/closings/'.$closing->id.'/corrections', [
                'lines' => [['product_id' => $this->product->id, 'qty_remaining' => 18, 'qty_wasted' => 1]],
                'qris' => 0, 'transfer' => 0, 'reason' => 'Hitung ulang cups',
            ])->assertForbidden();
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/closings/'.$closing->id.'/corrections', [
                'lines' => [['product_id' => $this->product->id, 'qty_remaining' => 18, 'qty_wasted' => 1]],
                'qris' => 0, 'transfer' => 0, 'reason' => 'Hitung ulang cups',
            ])->assertOk();
        $this->assertIntegrated('Koreksi Closing');
        $this->actingAs($this->runner, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/close-out', ['kitchen_id' => $this->kitchen->id,
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 17]]])->assertOk();
        $this->assertIntegrated('Tutup Coffee Bike');
        $this->assertDatabaseHas('cart_closeout_variances', ['cart_id' => $this->cart->id,
            'product_id' => $this->product->id, 'variance_qty' => -1]);

        PermissionMatrix::set(Role::FINANCE, PanelModule::CENTRAL_STOCK, ['view', 'edit']);
        PermissionMatrix::forget();
        $current = (int) DB::table('stock_ledger')->where('location_type', 'kitchen')
            ->where('location_id', $this->kitchen->id)->where('product_id', $this->product->id)->sum('qty_delta');
        Livewire::actingAs($this->finance)->test(CentralStock::class)
            ->callAction('stockAdjustment', ['item_kind' => 'product', 'location_kind' => 'kitchen',
                'location_id' => $this->kitchen->id, 'product_id' => $this->product->id,
                'counted_qty' => $current - 1, 'reason' => 'Satu cup rusak saat hitung ulang sore']);
        $this->assertIntegrated('Penyesuaian stok');

        $page = Livewire::actingAs($this->finance)->test(StockOpnameEntry::class)
            ->set('locationKind', 'kitchen')->set('locationId', $this->kitchen->id)
            ->call('loadLocation');
        $page->set('lines.0.counted_qty', $current - 2)
            ->set('reason', 'Hitung ulang fisik sebelum setoran')->call('submit');
        $opname = StockOpname::query()->sole();
        $this->assertIntegrated('Stok opname draft');
        Livewire::actingAs($this->finance)->test(ListStockOpnames::class)
            ->callTableAction('apply', $opname)->assertHasNoTableActionErrors();
        $this->assertIntegrated('Stok opname applied');

        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/settlements', ['cart_id' => $this->cart->id,
                'cash' => 15000, 'qris' => 0, 'transfer' => 0])->assertCreated();
        $this->assertIntegrated('Setoran');
        $settlement = Settlement::query()->sole();
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/settlements/'.$settlement->id.'/approve', ['lines' => []])->assertOk();
        $this->assertIntegrated('Setoran approved');
    }

    public function test_unpaired_transfer_and_missing_journal_are_reported_once_without_changing_stock(): void
    {
        $manager = User::factory()->role(Role::ADMINISTRATOR)->create(['is_management' => true]);
        $row = ['location_type' => 'cart', 'location_id' => $this->cart->id,
            'product_id' => $this->product->id, 'movement_type' => MovementType::ALLOCATION_IN,
            'qty_delta' => 1, 'cost_minor' => 1000, 'actor_id' => $this->finance->id,
            'kitchen_id' => $this->kitchen->id, 'ref_type' => 'injected', 'ref_id' => 75];
        StockLedger::create($row);
        $before = DB::table('stock_ledger')->count();
        Artisan::call('soul:stock-reconcile');
        $this->assertDatabaseHas('stock_reconciliation_results', ['status' => 'MISMATCH']);
        $codes = array_column(json_decode(DB::table('stock_reconciliation_results')->value('findings'), true), 'code');
        $this->assertContains('UNPAIRED_TRANSFER', $codes);
        $this->assertContains('TOTAL_MISMATCH', $codes);
        $this->assertContains('BOOK_MISMATCH', $codes);
        $this->assertSame($before, DB::table('stock_ledger')->count());
        $this->assertEqualsCanonicalizing([$this->finance->id, $this->manager->id, $manager->id],
            AppNotification::query()->where('type', 'StockReconciliationMismatch')->pluck('user_id')->all());
        Artisan::call('soul:stock-reconcile');
        $this->assertSame($before, DB::table('stock_ledger')->count());
        $this->assertSame(3, AppNotification::query()->where('type', 'StockReconciliationMismatch')->count());
        $this->assertSame(1, DB::table('stock_reconciliation_results')->count());
    }

    public function test_missing_inventory_journal_is_reported_without_transfer_false_positive(): void
    {
        $other = Product::create(['code' => 'T075-CANDIDATE', 'name' => 'Kopi Pembanding',
            'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 2, 'is_active' => true]);
        StockLedger::create(['location_type' => 'kitchen', 'location_id' => $this->kitchen->id,
            'product_id' => $other->id, 'movement_type' => MovementType::PRODUCTION_IN,
            'qty_delta' => 1, 'cost_minor' => 0, 'actor_id' => $this->production->id,
            'kitchen_id' => $this->kitchen->id]);
        StockLedger::create(['location_type' => 'kitchen', 'location_id' => $this->kitchen->id,
            'product_id' => $this->product->id, 'movement_type' => MovementType::PRODUCTION_IN,
            'qty_delta' => 2, 'cost_minor' => 2000, 'actor_id' => $this->production->id,
            'kitchen_id' => $this->kitchen->id, 'ref_type' => 'injected', 'ref_id' => 76]);

        Artisan::call('soul:stock-reconcile');
        $findings = json_decode(DB::table('stock_reconciliation_results')->value('findings'), true);
        $book = collect($findings)->firstWhere('code', 'BOOK_MISMATCH');
        $this->assertSame(2000, $book['ledger']);
        $this->assertSame(0, $book['compared']);
        $this->assertStringContainsString('Kopi Pembanding', $book['stockCandidates']);
        $this->assertStringContainsString('Kopi Integrated', $book['stockCandidates']);
        $this->assertNotContains('UNPAIRED_TRANSFER', array_column($findings, 'code'));
        $this->assertSame(2, DB::table('stock_ledger')->count());
        $body = AppNotification::query()->where('type', 'StockReconciliationMismatch')->firstOrFail()->payload_json['body'];
        $this->assertStringContainsString('Dapur Integrated', $body);
        $this->assertStringContainsString('Kopi Integrated', $body);
        $this->assertStringContainsString('Kopi Pembanding', $body);
        $this->assertStringContainsString('sumber selisih belum pasti', $body);
        $this->assertStringContainsString('ledger 2000 vs tampilan/buku besar 0', $body);
    }

    public function test_book_gap_with_zero_current_stock_still_names_ledger_location_and_product(): void
    {
        $ledger = app(StockLedgerService::class);
        $ledger->post('kitchen', $this->kitchen->id, $this->product->id,
            MovementType::PRODUCTION_IN, 1, $this->production->id, $this->kitchen->id);
        $ledger->post('kitchen', $this->kitchen->id, $this->product->id,
            MovementType::SALE_OUT, 1, $this->production->id, $this->kitchen->id);
        $this->assertSame(0, $ledger->stockFor('kitchen', $this->kitchen->id, $this->product->id));

        Artisan::call('soul:stock-reconcile');
        $findings = json_decode(DB::table('stock_reconciliation_results')->value('findings'), true);
        $book = collect($findings)->firstWhere('code', 'BOOK_MISMATCH');
        $this->assertSame(0, $book['ledger']);
        $this->assertSame(-1000, $book['compared']);
        $this->assertStringContainsString('Dapur Integrated / Kopi Integrated', $book['stockCandidates']);
        $body = AppNotification::query()->where('type', 'StockReconciliationMismatch')->firstOrFail()->payload_json['body'];
        $this->assertStringContainsString('Dapur Integrated / Kopi Integrated', $body);
        $this->assertStringContainsString('sumber selisih belum pasti', $body);
    }

    public function test_zero_sum_transfer_at_the_same_location_is_still_reported(): void
    {
        foreach ([
            [MovementType::PRODUCTION_IN, 1, 'brew', 1],
            [MovementType::ALLOCATION_OUT, -1, 'injected', 75],
            [MovementType::ALLOCATION_IN, 1, 'injected', 75],
        ] as [$type, $qty, $refType, $refId]) {
            StockLedger::create(['location_type' => 'kitchen', 'location_id' => $this->kitchen->id,
                'product_id' => $this->product->id, 'movement_type' => $type,
                'qty_delta' => $qty, 'cost_minor' => 0, 'actor_id' => $this->production->id,
                'kitchen_id' => $this->kitchen->id, 'ref_type' => $refType, 'ref_id' => $refId]);
        }
        $findings = app(StockReconciliationService::class)->findings();
        $transfer = collect($findings)->firstWhere('code', 'UNPAIRED_TRANSFER');
        $this->assertSame(0, $transfer['ledger']);
        $this->assertSame(0, $transfer['compared']);
        $this->assertStringContainsString('ALLOCATION_IN@kitchen', $transfer['reference']);
        $this->assertNotContains('TOTAL_MISMATCH', array_column($findings, 'code'));
    }

    public function test_transfer_to_the_wrong_cart_is_reported_even_when_quantities_cancel(): void
    {
        $other = Cart::create(['code' => 'T075-WRONG', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $assignment = StaffAssignment::query()->sole();
        foreach ([
            [MovementType::PRODUCTION_IN, 'kitchen', $this->kitchen->id, 1],
            [MovementType::ALLOCATION_OUT, 'kitchen', $this->kitchen->id, -1],
            [MovementType::ALLOCATION_IN, 'cart', $other->id, 1],
        ] as [$type, $locationType, $locationId, $qty]) {
            StockLedger::create(['location_type' => $locationType, 'location_id' => $locationId,
                'product_id' => $this->product->id, 'movement_type' => $type,
                'qty_delta' => $qty, 'cost_minor' => 0, 'actor_id' => $this->production->id,
                'kitchen_id' => $this->kitchen->id,
                'ref_type' => $type === MovementType::PRODUCTION_IN ? 'brew' : 'showcase_handover',
                'ref_id' => $type === MovementType::PRODUCTION_IN ? 1 : $assignment->id]);
        }
        $this->assertContains('UNPAIRED_TRANSFER',
            array_column(app(StockReconciliationService::class)->findings(), 'code'));
    }

    public function test_a_replenished_negative_history_stays_visible(): void
    {
        foreach ([[-1, MovementType::SALE_OUT], [1, MovementType::PRODUCTION_IN]] as [$qty, $type]) {
            StockLedger::create(['location_type' => 'kitchen', 'location_id' => $this->kitchen->id,
                'product_id' => $this->product->id, 'movement_type' => $type,
                'qty_delta' => $qty, 'cost_minor' => 0, 'actor_id' => $this->production->id,
                'kitchen_id' => $this->kitchen->id]);
        }
        $findings = app(StockReconciliationService::class)->findings();
        $history = collect($findings)->firstWhere('code', 'NEGATIVE_HISTORY');
        $this->assertSame(-1, $history['ledger']);
        $this->assertNotContains('NEGATIVE_BALANCE', array_column($findings, 'code'));
    }

    public function test_pending_cost_is_reported_separately_without_hiding_a_real_book_gap(): void
    {
        $unpriced = Product::create(['code' => 'T075-PENDING', 'name' => 'Kopi Menunggu HPP',
            'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 2, 'is_active' => true]);
        app(StockLedgerService::class)->post('kitchen', $this->kitchen->id, $unpriced->id,
            MovementType::PRODUCTION_IN, 1, $this->production->id, $this->kitchen->id);
        $this->assertSame([], app(StockReconciliationService::class)->findings());
        Artisan::call('soul:stock-reconcile');
        $this->assertDatabaseHas('stock_reconciliation_results', ['status' => 'BALANCED', 'pending_cost_rows' => 1]);

        StockLedger::create(['location_type' => 'kitchen', 'location_id' => $this->kitchen->id,
            'product_id' => $this->product->id, 'movement_type' => MovementType::PRODUCTION_IN,
            'qty_delta' => 1, 'cost_minor' => 1000, 'actor_id' => $this->production->id,
            'kitchen_id' => $this->kitchen->id]);
        $this->assertContains('BOOK_MISMATCH',
            array_column(app(StockReconciliationService::class)->findings(), 'code'));
    }

    public function test_writer_refuses_a_negative_balance_before_accepting_a_movement(): void
    {
        try {
            app(StockLedgerService::class)->post('cart', $this->cart->id, $this->product->id,
                MovementType::SALE_OUT, 1, $this->rider->id, $this->kitchen->id);
            $this->fail('Negative balance was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Stok di lokasi ini kurang', $exception->getMessage());
        }
        $this->assertDatabaseCount('stock_ledger', 0);
    }

    public function test_dry_run_checks_without_writing_results_or_notifications(): void
    {
        Artisan::call('soul:stock-reconcile', ['--dry-run' => true]);
        $this->assertStringContainsString('Seimbang', Artisan::output());
        $this->assertDatabaseCount('stock_reconciliation_results', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_scheduler_is_registered_and_reject_dapur_waits_for_t074(): void
    {
        $schedule = collect(Schedule::events())->first(fn ($event): bool => str_contains($event->command, 'soul:stock-reconcile'));
        $this->assertNotNull($schedule);
        $this->assertSame('30 0 * * *', $schedule->expression);
        $this->assertTrue($schedule->withoutOverlapping);
        $this->assertTrue($schedule->onOneServer);
    }

    public function test_reject_dapur_is_added_when_t074_reaches_main(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('kitchen_rejects')) {
            $this->markTestIncomplete('T-074 belum ada di main; jalur Reject Dapur perlu ditambahkan ke tes ini.');
        }

        $this->markTestIncomplete('T-074 sudah ada; jalur Reject Dapur perlu ditambahkan sebelum tes ini dianggap lengkap.');
    }
}
