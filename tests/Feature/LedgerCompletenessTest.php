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
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\DailyAllowanceService;
use App\Services\InventoryJournalService;
use App\Services\JournalService;
use App\Services\ProductionService;
use App\Services\RecipeService;
use App\Services\Reporting\InventoryAccountingReportService;
use App\Services\StockLedgerService;
use App\Services\StockOpnameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LedgerCompletenessTest extends TestCase
{
    use RefreshDatabase;

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
        app(DailyAllowanceService::class)->recordGiven($allowance, $rider, $finance);
        app(DailyAllowanceService::class)->recordGiven($allowance, $rider, $finance);
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
            MovementType::PURCHASE_IN, 10, $barista->id, $kitchen->id, rawMaterialId: $raw->id, costMinor: 12000);
        app(RecipeService::class)->create($product->id, [['raw_material_id' => $raw->id, 'qty_per_unit' => 1]], $barista);
        app(RecipeService::class)->refreshCostsForRawMaterials([$raw->id], $kitchen->id);
        $row = app(InventoryAccountingReportService::class)->costVariance(today())[0];
        $this->assertSame(200, $row['difference_minor']);
        $this->assertSame(20, $row['difference_percent']);
        $this->assertTrue($row['flagged']);
    }

    private function line(string $code, int $debit, int $credit): array
    {
        return ['account_id' => Account::query()->where('code', $code)->firstOrFail()->id,
            'debit_minor' => $debit, 'credit_minor' => $credit];
    }
}
