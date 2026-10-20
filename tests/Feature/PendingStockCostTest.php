<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\InventoryAccounting;
use App\Filament\Pages\PendingInventoryCosts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Account;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\AccountingPeriodService;
use App\Services\JournalService;
use App\Services\PendingStockCostService;
use App\Services\Reporting\InventoryAccountingReportService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PendingStockCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_stock_is_revalued_once_with_first_later_hpp_and_balanced_journal(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Pending', 'address' => 'Jalan Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $product = Product::create(['code' => 'PEND052', 'name' => 'Kopi Pending', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        $cart = Cart::create(['code' => 'PEND052', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $ledger = app(StockLedgerService::class);
        $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::PRODUCTION_IN, 10, $finance->id, $kitchen->id, 'test_pending', 1);
        $ledger->transfer(StockLedgerService::KITCHEN, $kitchen->id, StockLedgerService::CART,
            $cart->id, $product->id, MovementType::ALLOCATION_OUT, MovementType::ALLOCATION_IN,
            4, $finance->id, $kitchen->id, 'test_pending_transfer', 1);
        $ledger->post(StockLedgerService::CART, $cart->id, $product->id,
            MovementType::SALE_OUT, 2, $finance->id, $kitchen->id, 'test_pending_sale', 1);
        $ledger->post(StockLedgerService::CART, $cart->id, $product->id,
            MovementType::WASTE_OUT, 1, $finance->id, $kitchen->id, 'test_pending_waste', 1);
        $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::OPNAME_ADJUSTMENT, 1, $finance->id, $kitchen->id, 'stock_opname', 1);

        $this->assertSame(6, StockLedger::query()->where('cost_pending', true)->count());
        $this->assertSame(6, app(InventoryAccountingReportService::class)->pendingCosts()[0]['count']);
        $this->assertSame(0, app(InventoryAccountingReportService::class)->pendingCosts()[0]['estimated_minor']);
        $this->assertSame(6, app(InventoryAccountingReportService::class)->pendingCosts()[0]['unpriced_count']);
        PermissionMatrix::set(Role::FINANCE, PanelModule::PRODUCTS, ['view']);
        $this->actingAs($finance)->get(InventoryAccounting::getUrl())->assertForbidden();
        $this->get(PendingInventoryCosts::getUrl())->assertOk()
            ->assertSee('Biaya Stok Tertunda');
        $this->actingAs($finance)->get(ProductResource::getUrl())->assertOk()
            ->assertSee('6 gerak perlu dinilai')->assertSee('HPP belum diisi');
        $this->assertSame(7, $ledger->stockFor(StockLedgerService::KITCHEN, $kitchen->id, $product->id));
        $this->assertSame(1, $ledger->stockFor(StockLedgerService::CART, $cart->id, $product->id));

        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 2000,
            'sell_price_minor' => 5000, 'effective_from' => now()->addSecond()]);
        $this->assertSame(22000, app(InventoryAccountingReportService::class)->pendingCosts()[0]['estimated_minor']);
        $this->assertSame(8000, app(InventoryAccountingReportService::class)->pendingCosts()[0]['stock_impact_minor']);

        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        app(AccountingPeriodService::class)->setStatus(today()->year, today()->month, 'CLOSED', $manager);
        try {
            app(PendingStockCostService::class)->resolveProduct($product, $finance);
            $this->fail('Closed period accepted inventory revaluation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry_date', $exception->errors());
        }
        $this->assertDatabaseCount('stock_cost_resolutions', 0);
        app(AccountingPeriodService::class)->setStatus(today()->year, today()->month, 'OPEN', $manager, 'Nilai ulang stok uji');

        Livewire::test(PendingInventoryCosts::class)->call('revalueProduct', $product->id)->assertHasNoErrors();
        $this->assertSame(0, app(PendingStockCostService::class)->resolveProduct($product, $finance));
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'stock_cost_revaluation')->count());
        $this->assertDatabaseCount('stock_cost_resolutions', 6);
        $this->assertSame(0, app(InventoryAccountingReportService::class)->pendingCosts()[0]['count'] ?? 0);
        $this->assertSame(6, StockLedger::query()->where('cost_pending', true)->where('cost_minor', 0)->count());
        $this->assertSame(7000, $ledger->valuation(StockLedgerService::KITCHEN, $kitchen->id, $product->id)[1]);
        $this->assertSame(1000, $ledger->valuation(StockLedgerService::CART, $cart->id, $product->id)[1]);
        $this->assertSame(8000, app(InventoryAccountingReportService::class)->inventoryGap(today())[0]['physical_minor']);
        $this->assertSame(8000, app(InventoryAccountingReportService::class)->inventoryGap(today())[0]['book_minor']);
        $this->assertSame((int) DB::table('journal_lines')->sum('debit_minor'),
            (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    public function test_wrong_role_gets_403_when_revaluing_pending_stock(): void
    {
        $rider = User::factory()->role(Role::STAFF)->create();
        $product = Product::create(['code' => 'AUTH052', 'name' => 'Kopi Auth', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);

        Route::get('/test/revalue-pending-stock',
            fn () => app(PendingStockCostService::class)->resolveProduct($product, $rider));
        $this->actingAs($rider)->get('/test/revalue-pending-stock')->assertForbidden();
    }

    public function test_revaluation_reprices_later_transfer_from_mixed_known_and_pending_stock(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Campur', 'address' => 'Jalan Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $cart = Cart::create(['code' => 'MIX052', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $product = Product::create(['code' => 'MIX052', 'name' => 'Kopi Campur', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        $ledger = app(StockLedgerService::class);
        $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::PRODUCTION_IN, 10, $finance->id, $kitchen->id, 'mixed_pending', 1);
        $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::PRODUCTION_IN, 10, $finance->id, $kitchen->id, 'mixed_known', 2,
            costMinor: 20000);
        app(JournalService::class)->post([
            'entry_date' => today()->toDateString(), 'description' => 'Biaya produksi campuran',
            'source_type' => 'mixed_known', 'source_id' => 2, 'purpose' => 'TRANSFER',
        ], [
            ['account_id' => Account::query()->where('code', '1-1600')->value('id'),
                'debit_minor' => 20000, 'credit_minor' => 0],
            ['account_id' => Account::query()->where('code', '1-1700')->value('id'),
                'debit_minor' => 0, 'credit_minor' => 20000],
        ]);
        $ledger->transfer(StockLedgerService::KITCHEN, $kitchen->id, StockLedgerService::CART,
            $cart->id, $product->id, MovementType::ALLOCATION_OUT, MovementType::ALLOCATION_IN,
            5, $finance->id, $kitchen->id, 'mixed_transfer', 1);
        $this->assertSame(5000, $ledger->valuation(StockLedgerService::CART, $cart->id, $product->id)[1]);

        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()]);
        $this->assertSame(1, app(PendingStockCostService::class)->resolveProduct($product, $finance));
        $this->assertSame(22500, $ledger->valuation(StockLedgerService::KITCHEN, $kitchen->id, $product->id)[1]);
        $this->assertSame(7500, $ledger->valuation(StockLedgerService::CART, $cart->id, $product->id)[1]);
        $this->assertSame(30000, app(InventoryAccountingReportService::class)->inventoryGap(today())[0]['book_minor']);
        $this->assertSame(30000, app(InventoryAccountingReportService::class)->inventoryGap(today())[0]['physical_minor']);
        $sold = $ledger->post(StockLedgerService::CART, $cart->id, $product->id,
            MovementType::SALE_OUT, 1, $finance->id, $kitchen->id, 'mixed_sale', 1);
        $this->assertSame(1500, $sold->cost_minor);
    }

    public function test_sale_void_after_revaluation_restores_resolved_cost_without_new_hpp(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Void', 'address' => 'Jalan Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $product = Product::create(['code' => 'VOIDP052', 'name' => 'Kopi Void Pending', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        $ledger = app(StockLedgerService::class);
        $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::PRODUCTION_IN, 2, $finance->id, $kitchen->id, 'void_pending', 1);
        $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::SALE_OUT, 1, $finance->id, $kitchen->id, 'void_sale', 1);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()]);
        app(PendingStockCostService::class)->resolveProduct($product, $finance);

        $void = $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::SALE_VOID_IN, 1, $finance->id, $kitchen->id, 'void_sale_void', 1);
        $this->assertSame(1000, $void->cost_minor);
        $this->assertFalse($void->cost_pending);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'stock_ledger',
            'source_id' => $void->id, 'purpose' => 'SALE_COGS']);
        $this->assertSame(2000, app(InventoryAccountingReportService::class)->inventoryGap(today())[0]['book_minor']);
    }

    public function test_pre_cutover_pending_rows_are_not_revalued_over_opening_balance(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Cutover', 'address' => 'Jalan Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $product = Product::create(['code' => 'CUT052', 'name' => 'Kopi Cutover', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        $ledger = app(StockLedgerService::class);
        $yesterday = now()->subDay();
        $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::PRODUCTION_IN, 2, $finance->id, $kitchen->id, 'before_cutover', 1,
            occurredAt: $yesterday);
        $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::WASTE_OUT, 1, $finance->id, $kitchen->id, 'before_cutover', 2,
            occurredAt: $yesterday);
        app(JournalService::class)->postOpening(today()->toDateString(), [
            ['account_id' => Account::query()->where('code', '1-1600')->value('id'),
                'debit_minor' => 1000, 'credit_minor' => 0],
            ['account_id' => Account::query()->where('code', '3-1200')->value('id'),
                'debit_minor' => 0, 'credit_minor' => 1000],
        ]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()]);

        $this->assertSame(0, app(PendingStockCostService::class)->resolveProduct($product, $finance));
        $this->assertSame([], app(InventoryAccountingReportService::class)->pendingCosts());
        $this->assertSame(1000, (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', '1-1600')->sum('l.debit_minor'));
    }

    public function test_explicit_zero_production_cost_is_not_marked_pending_when_hpp_exists(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Nol', 'address' => 'Jalan Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $product = Product::create(['code' => 'ZERO052', 'name' => 'Kopi Nol', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);

        $row = app(StockLedgerService::class)->post(StockLedgerService::KITCHEN, $kitchen->id,
            $product->id, MovementType::PRODUCTION_IN, 1, $finance->id, $kitchen->id,
            'zero_recipe_cost', 1, costMinor: 0);
        $this->assertSame(0, $row->cost_minor);
        $this->assertFalse($row->cost_pending);
    }

    public function test_first_later_hpp_wins_over_newer_backdated_version_for_pending_waste(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Versi', 'address' => 'Jalan Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $product = Product::create(['code' => 'VERS052', 'name' => 'Kopi Versi', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        $waste = app(StockLedgerService::class)->post(StockLedgerService::KITCHEN, $kitchen->id,
            $product->id, MovementType::WASTE_OUT, 1, $finance->id, $kitchen->id, 'waste_version', 1);
        $first = ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 2000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);

        app(PendingStockCostService::class)->resolveProduct($product, $finance);
        $this->assertDatabaseHas('stock_cost_resolutions', ['stock_ledger_id' => $waste->id,
            'product_price_version_id' => $first->id, 'resolved_cost_minor' => 1000]);
    }

    public function test_downward_sale_cost_revaluation_voids_at_corrected_amount(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Turun', 'address' => 'Jalan Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $product = Product::create(['code' => 'DOWN052', 'name' => 'Kopi Turun', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        $ledger = app(StockLedgerService::class);
        $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::PRODUCTION_IN, 10, $finance->id, $kitchen->id, 'down_pending', 1);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 500,
            'sell_price_minor' => 5000, 'effective_from' => now()->subMinute()]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subMinute()]);
        $sale = $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::SALE_OUT, 1, $finance->id, $kitchen->id, 'down_sale', 1);
        $this->assertSame(1000, $sale->cost_minor);

        app(PendingStockCostService::class)->resolveProduct($product, $finance);
        $this->assertDatabaseHas('stock_cost_resolutions', ['stock_ledger_id' => $sale->id,
            'resolved_cost_minor' => -500]);
        $void = $ledger->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::SALE_VOID_IN, 1, $finance->id, $kitchen->id, 'down_sale_void', 1);
        $this->assertSame(500, $void->cost_minor);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'stock_ledger',
            'source_id' => $void->id, 'purpose' => 'SALE_COGS_REVALUE_VOID']);
        $this->assertSame(5000, app(InventoryAccountingReportService::class)->inventoryGap(today())[0]['book_minor']);
    }
}
