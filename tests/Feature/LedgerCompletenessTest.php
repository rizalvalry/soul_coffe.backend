<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RawMaterial;
use App\Models\Recipe;
use App\Models\Settlement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\AttendanceService;
use App\Services\CentralStockService;
use App\Services\ClearingService;
use App\Services\DailyAllowanceService;
use App\Services\InventoryJournalService;
use App\Services\JournalService;
use App\Services\ProductionService;
use App\Services\PurchaseOrderService;
use App\Services\RecipeService;
use App\Services\Reporting\InventoryAccountingReportService;
use App\Services\StockLedgerService;
use App\Services\StockOpnameService;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LedgerCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_date_locks_chart_before_accounting_period(): void
    {
        DB::enableQueryLog();
        try {
            DB::transaction(fn () => app(JournalService::class)->assertOpenDate(today()->toDateString()));
            $queries = array_map(fn (array $row): string => strtolower($row['query']), DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $chartLock = null;
        $periodLock = null;
        foreach ($queries as $index => $sql) {
            if (str_contains($sql, 'for update') && str_contains($sql, 'accounts')) {
                $chartLock ??= $index;
            }
            if (str_contains($sql, 'for update') && str_contains($sql, 'accounting_periods')) {
                $periodLock ??= $index;
            }
        }
        $this->assertNotNull($chartLock);
        $this->assertNotNull($periodLock);
        $this->assertLessThan($periodLock, $chartLock);
    }

    public function test_real_po_production_closing_taxed_settlement_and_opname_keep_moving_average_balanced(): void
    {
        config(['outbox.publish_mode' => 'queue']);
        $barista = User::factory()->role(Role::BARISTA)->create();
        $finance = User::factory()->role(Role::FINANCE)->create();
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $rider = User::factory()->role(Role::STAFF)->create();
        app(TaxService::class)->saveSetting($management, [
            'tax_type' => 'PBJT', 'is_active' => true, 'rate_bps' => 1000,
            'price_mode' => 'INCLUDED', 'scheme' => null,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'legal_note' => null, 'monthly_threshold_minor' => null, 'reason' => 'Tarif uji integrasi',
        ]);
        $kitchen = CentralKitchen::create(['name' => 'Dapur Siklus', 'address' => 'Jalan Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $barista->update(['kitchen_id' => $kitchen->id]);
        $cart = Cart::create(['code' => 'CYCLE052', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $location = Location::create(['name' => 'Titik Siklus', 'lat' => -6.18, 'lng' => 106.88]);
        $product = Product::create(['code' => 'CYCLE052', 'name' => 'Kopi Siklus', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);
        $raw = RawMaterial::create(['code' => 'RAW-CYCLE052', 'name' => 'Bahan Siklus',
            'unit' => 'pcs', 'is_active' => true, 'sort_order' => 1]);
        $supplier = Supplier::create(['code' => 'SUP-CYCLE052', 'name' => 'Pemasok Siklus', 'is_active' => true]);
        app(RecipeService::class)->create($product->id,
            [['raw_material_id' => $raw->id, 'qty_per_unit' => 1]], $barista);
        $orders = app(PurchaseOrderService::class);
        $po = $orders->create($supplier->id, $kitchen->id,
            [['raw_material_id' => $raw->id, 'qty_ordered' => 10, 'unit_cost_minor' => 1200]], $finance);
        $orders->receive($orders->markOrdered($po, $finance), [], $finance);
        $production = app(ProductionService::class)->brew($kitchen->id, [$product->id => 10], $barista);
        $this->assertSame(1200, $production->lines->first()->recipe_cost_minor);
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($barista);
        $attendance->openStaffWindow($barista);
        $attendance->clockIn($rider);
        app(CentralStockService::class)->handToCart($barista, $cart, $rider, [$product->id => 8], 0,
            locationId: $location->id);
        $this->actingAs($rider, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/closing', [
                'lines' => [['product_id' => $product->id, 'qty_remaining' => 2, 'qty_wasted' => 1]],
                'qris' => 10000, 'transfer' => 5000, 'gps_lat' => -6.18, 'gps_lng' => 106.88,
            ])->assertCreated();
        $gap = app(InventoryAccountingReportService::class)->inventoryGap(today());
        $this->assertSame(4800, $gap[0]['physical_minor']);
        $this->assertSame(4800, $gap[0]['book_minor']);
        $this->assertSame(0, $gap[1]['gap_minor']);
        $settlementId = $this->actingAs($finance, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', ['cart_id' => $cart->id, 'cash' => 10000,
                'qris' => 9000, 'transfer' => 6000])->assertCreated()->json('data.id');
        $this->assertTrue(Settlement::query()->findOrFail($settlementId)->method_variance_flagged);
        $this->actingAs($finance, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements/'.$settlementId.'/approve', [
                'lines' => [['product_id' => $product->id, 'qty_returned' => 2, 'qty_rejected' => 0]],
            ])->assertOk();
        $settlementLines = JournalEntry::query()->where('source_type', 'settlement')
            ->where('source_id', $settlementId)->where('purpose', 'SETTLEMENT')->sole()
            ->lines()->with('account')->get()
            ->mapWithKeys(fn ($line): array => [$line->account->code => [$line->debit_minor, $line->credit_minor]])->all();
        $this->assertSame(['1-1200' => [10000, 0], '1-1400' => [15000, 0],
            '4-1100' => [0, 22727], '2-1500' => [0, 2273]], $settlementLines);
        $saleCost = JournalEntry::query()->where('purpose', 'SALE_COGS')
            ->where('source_type', 'stock_ledger')->sole();
        $this->assertSame(['5-1100' => [6000, 0], '1-1600' => [0, 6000]],
            $saleCost->lines()->with('account')->get()
                ->mapWithKeys(fn ($line): array => [$line->account->code => [$line->debit_minor, $line->credit_minor]])->all());
        $clearing = app(ClearingService::class);
        $receipt = $clearing->record($finance, [
            'uuid' => (string) Str::uuid(), 'received_on' => today()->toDateString(),
            'amount_minor' => 14700, 'mdr_minor' => 300,
            'bank_account_id' => Account::query()->where('code', '1-1300')->value('id'),
            'sale_dates' => [today()->toDateString()],
        ]);
        $this->assertSame(15000, (int) $receipt->allocations->sum('amount_minor'));
        $this->assertSame(0, $clearing->aging(today()->toDateString())['net_balance_minor']);
        $clearingLines = JournalEntry::query()->where('source_type', 'clearing_receipt')
            ->where('source_id', $receipt->id)->sole()->lines()->with('account')->get()
            ->mapWithKeys(fn ($line): array => [$line->account->code => [$line->debit_minor, $line->credit_minor]])->all();
        $this->assertSame(['1-1300' => [14700, 0], '1-1400' => [0, 15000],
            '6-1990' => [300, 0]], $clearingLines);
        $opname = app(StockOpnameService::class)->create(StockLedgerService::KITCHEN, $kitchen->id,
            [['product_id' => $product->id, 'counted_qty' => 3]], 'Hitung fisik siklus', $finance);
        app(StockOpnameService::class)->apply($opname, $finance);
        $gap = app(InventoryAccountingReportService::class)->inventoryGap(today());
        $this->assertSame(3600, $gap[0]['physical_minor']);
        $this->assertSame(3600, $gap[0]['book_minor']);
        $this->assertSame(0, $gap[1]['gap_minor']);
        $this->assertSame((int) DB::table('journal_lines')->sum('debit_minor'),
            (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    public function test_production_void_checks_original_batch_even_when_newer_stock_remains(): void
    {
        $barista = User::factory()->role(Role::BARISTA)->create();
        $finance = User::factory()->role(Role::FINANCE)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Batch', 'address' => 'Jalan Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $cart = Cart::create(['code' => 'BATCH052', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $product = Product::create(['code' => 'BATCH052', 'name' => 'Kopi Batch', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);
        $first = app(ProductionService::class)->brew($kitchen->id, [$product->id => 5], $barista);
        app(ProductionService::class)->brew($kitchen->id, [$product->id => 5], $barista);
        $ledger = app(StockLedgerService::class);
        $ledger->transfer(StockLedgerService::KITCHEN, $kitchen->id, StockLedgerService::CART, $cart->id,
            $product->id, MovementType::ALLOCATION_OUT, MovementType::ALLOCATION_IN, 5,
            $barista->id, $kitchen->id, 'batch_test', 1);
        $this->assertSame(0, $ledger->remainingProductionBatch($kitchen->id, $product->id, $first->id));
        try {
            app(ProductionService::class)->void($first, 'Produksi salah', $finance);
            $this->fail('Voiding an already transferred batch was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('production', $exception->errors());
        }
        $ledger->transfer(StockLedgerService::CART, $cart->id, StockLedgerService::KITCHEN, $kitchen->id,
            $product->id, MovementType::RETURN_OUT, MovementType::RETURN_IN, 5,
            $barista->id, $kitchen->id, 'batch_return', 1);
        $this->assertSame(5, $ledger->remainingProductionBatch($kitchen->id, $product->id, $first->id));
        app(ProductionService::class)->void($first, 'Produksi salah', $finance);
        $this->assertSame(5, $ledger->stockFor(StockLedgerService::KITCHEN, $kitchen->id, $product->id));
        $this->assertGreaterThan(0, app(InventoryAccountingReportService::class)->inventoryGap(today())[0]['fallback_rows']);
    }

    public function test_null_recipe_cost_uses_product_hpp_fallback_and_posts_production_journal(): void
    {
        $barista = User::factory()->role(Role::BARISTA)->create();
        $finance = User::factory()->role(Role::FINANCE)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur HPP', 'address' => 'Jalan Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $product = Product::create(['code' => 'HPP052', 'name' => 'Kopi HPP', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);
        $raw = RawMaterial::create(['code' => 'RAW-HPP052', 'name' => 'Bahan HPP',
            'unit' => 'pcs', 'is_active' => true, 'sort_order' => 1]);
        app(StockLedgerService::class)->post(StockLedgerService::RAW_MATERIAL_STORE, $kitchen->id, null,
            MovementType::PURCHASE_IN, 1, $finance->id, $kitchen->id, rawMaterialId: $raw->id, costMinor: 1200);
        $recipe = app(RecipeService::class)->create($product->id,
            [['raw_material_id' => $raw->id, 'qty_per_unit' => 1]], $barista);
        Recipe::query()->whereKey($recipe->id)->update(['computed_cost_minor' => null]);

        $production = app(ProductionService::class)->brew($kitchen->id, [$product->id => 1], $barista);

        $this->assertNull($production->lines->first()->recipe_cost_minor);
        $this->assertDatabaseHas('stock_ledger', ['ref_type' => 'production', 'ref_id' => $production->id,
            'movement_type' => MovementType::PRODUCTION_IN->value, 'cost_minor' => 1000, 'cost_fallback' => true]);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'production', 'source_id' => $production->id,
            'purpose' => 'TRANSFER']);
        $this->assertSame(1000, (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', '1-1600')->sum('l.debit_minor'));
    }

    public function test_receipt_production_waste_and_opname_match_physical_stock_and_balance(): void
    {
        $barista = User::factory()->role(Role::BARISTA)->create();
        $finance = User::factory()->role(Role::FINANCE)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Uji', 'address' => 'Jalan Uji', 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $product = Product::create(['code' => 'TEST052', 'name' => 'Kopi Uji', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);
        $raw = RawMaterial::create(['code' => 'RAW052', 'name' => 'Bahan Uji', 'unit' => 'pcs', 'is_active' => true, 'sort_order' => 1]);
        app(StockLedgerService::class)->post(StockLedgerService::RAW_MATERIAL_STORE, $kitchen->id, null,
            MovementType::PURCHASE_IN, 10, $finance->id, $kitchen->id, rawMaterialId: $raw->id, costMinor: 10000);
        app(JournalService::class)->post(['entry_date' => today()->toDateString(), 'description' => 'Terima bahan uji',
            'source_type' => 'test_receipt', 'source_id' => 1, 'purpose' => 'RECEIPT', 'posted_by' => $finance->id], [
                $this->line('1-1700', 10000, 0), $this->line('1-1100', 0, 10000),
            ]);
        app(RecipeService::class)->create($product->id, [['raw_material_id' => $raw->id, 'qty_per_unit' => 1]], $barista);
        app(RecipeService::class)->refreshCostsForRawMaterials([$raw->id], $kitchen->id);
        $production = app(ProductionService::class)->brew($kitchen->id, [$product->id => 10], $barista);
        $this->assertSame(1000, $production->lines->first()->recipe_cost_minor);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'production')->count());

        $waste = app(StockLedgerService::class)->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
            MovementType::WASTE_OUT, 2, $finance->id, $kitchen->id, 'test_waste', 1);
        app(InventoryJournalService::class)->postMovement($waste);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'stock_ledger')->where('source_id', $waste->id)->count());
        $opname = app(StockOpnameService::class)->create(StockLedgerService::KITCHEN, $kitchen->id,
            [['product_id' => $product->id, 'counted_qty' => 7]], 'Hitung fisik uji', $finance);
        app(StockOpnameService::class)->apply($opname, $finance);
        $report = app(InventoryAccountingReportService::class)->inventoryGap(today());
        $this->assertSame(7000, $report[0]['physical_minor']);
        $this->assertSame(7000, $report[0]['book_minor']);
        $this->assertSame(0, $report[0]['gap_minor']);
        $this->assertSame(0, $report[1]['gap_minor']);
        $this->assertSame((int) DB::table('journal_lines')->sum('debit_minor'), (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    public function test_allowance_posts_once_and_reopen_requires_reason_notifies_every_manager(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $managerA = User::factory()->role(Role::MANAGEMENT)->create();
        $managerB = User::factory()->role(Role::MANAGEMENT)->create();
        $rider = User::factory()->role(Role::STAFF)->create();
        $cart = Cart::create(['code' => 'T052', 'status' => 'active']);
        $allowance = app(DailyAllowanceService::class)->forCart($cart);
        $service = app(DailyAllowanceService::class);
        $this->assertSame(50000, $service->recordGiven($allowance, $rider, $finance));
        $this->assertSame(0, $service->recordGiven($allowance, $rider, $finance));
        $secondCart = Cart::create(['code' => 'T052-B', 'status' => 'active']);
        $secondAllowance = $service->forCart($secondCart);
        $this->assertSame(0, $service->recordGiven($secondAllowance, $rider, $finance));
        $this->assertSame(50000, $service->totalForCart($cart));
        $this->assertSame(0, $service->totalForCart($secondCart));
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'daily_allowance')->count());
        $this->assertSame(50000, (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', '6-1700')->sum('l.debit_minor'));

        app(AccountingPeriodService::class)->setStatus(today()->year, today()->month, 'CLOSED', $managerA);
        try {
            app(AccountingPeriodService::class)->setStatus(today()->year, today()->month, 'OPEN', $managerA);
            $this->fail('Reopen without reason was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
        }
        app(AccountingPeriodService::class)->setStatus(today()->year, today()->month, 'OPEN', $managerA, 'Koreksi saldo awal');
        $this->assertEqualsCanonicalizing([$managerA->id, $managerB->id],
            AppNotification::query()->where('type', 'AccountingPeriodReopened')->pluck('user_id')->all());
        $audit = AuditLog::query()->where('action', 'accounting_period.reopened')->firstOrFail();
        $this->assertSame('Koreksi saldo awal', $audit->after_json['reason']);
    }

    public function test_stock_waste_in_closed_period_rolls_back_and_production_void_reverses_journal_and_stock(): void
    {
        $barista = User::factory()->role(Role::BARISTA)->create();
        $finance = User::factory()->role(Role::FINANCE)->create();
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Uji', 'address' => 'Jalan Uji', 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $product = Product::create(['code' => 'VOID052', 'name' => 'Kopi Void', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);
        $raw = RawMaterial::create(['code' => 'VOIDRAW', 'name' => 'Bahan Void', 'unit' => 'pcs', 'is_active' => true, 'sort_order' => 1]);
        app(StockLedgerService::class)->post(StockLedgerService::RAW_MATERIAL_STORE, $kitchen->id, null,
            MovementType::PURCHASE_IN, 2, $barista->id, $kitchen->id, rawMaterialId: $raw->id, costMinor: 2000);
        app(RecipeService::class)->create($product->id, [['raw_material_id' => $raw->id, 'qty_per_unit' => 1]], $barista);
        app(RecipeService::class)->refreshCostsForRawMaterials([$raw->id], $kitchen->id);
        $production = app(ProductionService::class)->brew($kitchen->id, [$product->id => 2], $barista);
        app(AccountingPeriodService::class)->setStatus(today()->year, today()->month, 'CLOSED', $manager);
        try {
            app(StockLedgerService::class)->post(StockLedgerService::KITCHEN, $kitchen->id, $product->id,
                MovementType::WASTE_OUT, 1, $finance->id, $kitchen->id, 'test_waste', 2);
            $this->fail('Closed period accepted stock loss.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry_date', $exception->errors());
        }
        $this->assertSame(2, app(StockLedgerService::class)->stockFor(StockLedgerService::KITCHEN, $kitchen->id, $product->id));
        app(AccountingPeriodService::class)->setStatus(today()->year, today()->month, 'OPEN', $manager, 'Koreksi produksi uji');
        app(ProductionService::class)->void($production, 'Produksi salah catat', $finance);
        $this->assertSame(0, app(StockLedgerService::class)->stockFor(StockLedgerService::KITCHEN, $kitchen->id, $product->id));
        $this->assertNotNull(JournalEntry::query()->where('source_type', 'production')->firstOrFail()->reversal);
        $this->assertSame(1, AuditLog::query()->where('action', 'production.voided')->count());
    }

    public function test_monthly_cost_variance_flags_more_than_configured_threshold(): void
    {
        $barista = User::factory()->role(Role::BARISTA)->create();
        $kitchen = CentralKitchen::create(['name' => 'Dapur Uji', 'address' => 'Jalan Uji', 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $product = Product::create(['code' => 'VAR052', 'name' => 'Kopi Varian', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        ProductPriceVersion::create(['product_id' => $product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);
        $raw = RawMaterial::create(['code' => 'RAWVAR', 'name' => 'Bahan Uji', 'unit' => 'pcs', 'is_active' => true, 'sort_order' => 1]);
        app(StockLedgerService::class)->post(StockLedgerService::RAW_MATERIAL_STORE, $kitchen->id, null,
            MovementType::PURCHASE_IN, 10, $barista->id, $kitchen->id, rawMaterialId: $raw->id, costMinor: 11010);
        app(RecipeService::class)->create($product->id, [['raw_material_id' => $raw->id, 'qty_per_unit' => 1]], $barista);
        app(RecipeService::class)->refreshCostsForRawMaterials([$raw->id], $kitchen->id);
        $row = app(InventoryAccountingReportService::class)->costVariance(today())[0];
        $this->assertSame(101, $row['difference_minor']);
        $this->assertSame(10, $row['difference_percent']);
        $this->assertTrue($row['flagged']);
    }

    private function line(string $code, int $debit, int $credit): array
    {
        return ['account_id' => Account::query()->where('code', $code)->firstOrFail()->id,
            'debit_minor' => $debit, 'credit_minor' => $credit];
    }
}
