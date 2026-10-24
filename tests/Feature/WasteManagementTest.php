<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\WasteReason;
use App\Filament\Pages\WasteManagement;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\PendingStockCostService;
use App\Services\Reporting\WasteReportService;
use App\Services\StockLedgerService;
use App\Services\WasteTrackingService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WasteManagementTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Product $product;

    private User $finance;

    private User $production;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountSeeder::class);
        $this->kitchen = CentralKitchen::query()->create(['name' => 'Dapur Waste', 'address' => 'Jl Uji',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true]);
        $this->product = Product::query()->create(['code' => 'SOUL', 'name' => 'Soul Coffee',
            'unit' => 'cup', 'is_sellable' => true, 'is_active' => true, 'sort_order' => 1]);
        ProductPriceVersion::query()->create(['product_id' => $this->product->id,
            'cost_price_minor' => 4000, 'sell_price_minor' => 10000, 'effective_from' => now()->subDay()]);
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->production = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        app(StockLedgerService::class)->post('kitchen', $this->kitchen->id, $this->product->id,
            MovementType::PRODUCTION_IN, 10, $this->production->id, $this->kitchen->id);
    }

    public function test_promotion_uses_three_cups_and_posts_promotion_expense_once(): void
    {
        $key = (string) Str::uuid();
        $service = app(WasteTrackingService::class);
        $use = $service->recordPromotion($this->production, $key, $this->kitchen->id,
            $this->product->id, 3, 'Konten reels');
        $retry = $service->recordPromotion($this->production, $key, $this->kitchen->id,
            $this->product->id, 3, 'Konten reels');
        $this->assertSame($use->id, $retry->id);
        $waste = StockLedger::query()->findOrFail($use->stock_ledger_id);
        $this->assertSame(-3, $waste->qty_delta);
        $this->assertSame(12000, $waste->cost_minor);
        $this->assertSame(WasteReason::PROMOTION, $waste->wasteClassification->reason_code);
        $this->assertSame(7, StockLedger::projectedStock('kitchen', $this->kitchen->id, $this->product->id));
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'stock_ledger',
            'source_id' => $waste->id, 'purpose' => 'STOCK_LOSS']);
        $this->assertSame(12000, $this->accountAmount($waste->id, '6-1930', 'debit_minor'));
        $this->assertSame(12000, $this->accountAmount($waste->id, '1-1600', 'credit_minor'));
        $this->assertSame(0, $this->accountAmount($waste->id, '6-1910', 'debit_minor'));
        $this->assertDatabaseCount('promotional_uses', 1);
        $this->assertDatabaseCount('waste_classifications', 1);
    }

    public function test_pending_promotion_hpp_revalues_to_promotion_account(): void
    {
        $pending = Product::query()->create(['code' => 'SOUL-PENDING', 'name' => 'Soul Pending',
            'unit' => 'cup', 'is_sellable' => true, 'is_active' => true, 'sort_order' => 2]);
        app(StockLedgerService::class)->post('kitchen', $this->kitchen->id, $pending->id,
            MovementType::PRODUCTION_IN, 3, $this->finance->id, $this->kitchen->id);
        $use = app(WasteTrackingService::class)->recordPromotion($this->finance, (string) Str::uuid(),
            $this->kitchen->id, $pending->id, 2, 'Konten produk baru');
        $this->assertSame(0, (int) StockLedger::query()->findOrFail($use->stock_ledger_id)->cost_minor);
        ProductPriceVersion::query()->create(['product_id' => $pending->id,
            'cost_price_minor' => 1000, 'sell_price_minor' => 2000, 'effective_from' => now()]);
        app(PendingStockCostService::class)->resolveProduct($pending, $this->finance);
        $promotional = (int) DB::table('journal_entries as j')
            ->join('journal_lines as l', 'l.entry_id', '=', 'j.id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.source_type', 'stock_cost_revaluation')->where('a.code', '6-1930')
            ->sum('l.debit_minor');
        $this->assertSame(2000, $promotional);
        $this->assertSame(2000, (int) DB::table('stock_cost_resolutions')
            ->where('stock_ledger_id', $use->stock_ledger_id)->value('resolved_cost_minor'));
    }

    public function test_finance_correction_is_audited_without_changing_ledger_or_journal(): void
    {
        $use = app(WasteTrackingService::class)->recordPromotion($this->production, (string) Str::uuid(),
            $this->kitchen->id, $this->product->id, 3, 'Foto produk');
        $waste = StockLedger::query()->findOrFail($use->stock_ledger_id);
        $ledgerBefore = DB::table('stock_ledger')->where('id', $waste->id)->first();
        $journalBefore = DB::table('journal_lines')->orderBy('id')->get()->toArray();
        $count = StockLedger::query()->count();
        $key = (string) Str::uuid();
        app(WasteTrackingService::class)->correctReason($this->finance, $key,
            $waste->wasteClassification->id, WasteReason::DISCARDED, 'Salah kategori');
        app(WasteTrackingService::class)->correctReason($this->finance, $key,
            $waste->wasteClassification->id, WasteReason::DISCARDED, 'Salah kategori');
        $this->assertSame($count, StockLedger::query()->count());
        $this->assertEquals($ledgerBefore, DB::table('stock_ledger')->where('id', $waste->id)->first());
        $this->assertEquals($journalBefore, DB::table('journal_lines')->orderBy('id')->get()->toArray());
        $this->assertSame(WasteReason::DISCARDED, $waste->wasteClassification->fresh()->reason_code);
        $this->assertDatabaseHas('audit_log', ['action' => 'waste_reason.corrected',
            'subject_id' => $waste->wasteClassification->id]);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'waste_reason.corrected')->count());
        $this->assertDatabaseCount('waste_reason_corrections', 1);
    }

    public function test_monthly_matrix_uses_ledger_quantity_and_hpp_by_reason(): void
    {
        $service = app(StockLedgerService::class);
        $first = $service->post('kitchen', $this->kitchen->id, $this->product->id,
            MovementType::WASTE_OUT, 2, $this->production->id, $this->kitchen->id,
            'delivery_incident', 1);
        $second = $service->post('kitchen', $this->kitchen->id, $this->product->id,
            MovementType::WASTE_OUT, 1, $this->production->id, $this->kitchen->id,
            'other', 1);
        $sections = app(WasteReportService::class)->sections(today()->format('Y-m'), $this->kitchen->id);
        $rows = $sections['barang_terbuang']['rows'];
        $this->assertCount(3, $rows);
        $this->assertSame('Rusak/pecah', $rows[0][1]);
        $this->assertSame(2, $rows[0][count($rows[0]) - 3]);
        $this->assertSame($first->cost_minor, $rows[0][count($rows[0]) - 2]);
        $this->assertSame('Terbuang', $rows[1][1]);
        $this->assertSame(1, $rows[1][count($rows[1]) - 3]);
        $this->assertSame($second->cost_minor, $rows[1][count($rows[1]) - 2]);
        $this->assertSame(3, $rows[2][count($rows[2]) - 3]);
        $this->assertSame($first->cost_minor + $second->cost_minor, $rows[2][count($rows[2]) - 2]);
    }

    public function test_packaging_waste_tracks_stock_when_available_and_cost_only_otherwise(): void
    {
        $cup = RawMaterial::query()->create(['code' => 'CUP', 'name' => 'Cup kemasan',
            'unit' => 'pcs', 'is_active' => true, 'is_packaging_cup' => true]);
        $service = app(WasteTrackingService::class);
        $untracked = $service->recordPackaging($this->finance, (string) Str::uuid(), today()->toDateString(),
            $this->kitchen->id, $cup->id, 2, 500, 'Cup penyok');
        $this->assertNull($untracked->stock_ledger_id);
        $this->assertSame(1000, (int) $untracked->cost_minor);
        $this->assertDatabaseMissing('journal_entries', ['source_type' => 'packaging_waste',
            'source_id' => $untracked->id]);
        app(StockLedgerService::class)->post(StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id,
            null, MovementType::PURCHASE_IN, 10, $this->finance->id, $this->kitchen->id,
            rawMaterialId: $cup->id, costMinor: 5000);
        $tracked = $service->recordPackaging($this->finance, (string) Str::uuid(), today()->toDateString(),
            $this->kitchen->id, $cup->id, 3, null, null);
        $this->assertNotNull($tracked->stock_ledger_id);
        $this->assertSame(1500, (int) $tracked->cost_minor);
        $this->assertSame(7, app(StockLedgerService::class)->rawMaterialStockFor(
            StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $cup->id));
        $this->assertSame(1500, $this->accountAmount($tracked->stock_ledger_id, '6-1910', 'debit_minor'));
        $this->assertCount(1, app(WasteReportService::class)->sections(today()->format('Y-m'))['cup_kemasan']['rows']);
    }

    public function test_zero_valued_tracked_cups_need_valuation_before_writeoff(): void
    {
        $cup = RawMaterial::query()->create(['code' => 'CUP-ZERO', 'name' => 'Cup',
            'unit' => 'pcs', 'is_active' => true, 'is_packaging_cup' => true]);
        app(StockLedgerService::class)->post(StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id,
            null, MovementType::PURCHASE_IN, 5, $this->finance->id, $this->kitchen->id,
            rawMaterialId: $cup->id, costMinor: 0);
        try {
            app(WasteTrackingService::class)->recordPackaging($this->finance, (string) Str::uuid(),
                today()->toDateString(), $this->kitchen->id, $cup->id, 1, 500, null);
            $this->fail('Zero-valued tracked stock was written off with invented cost.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('rawMaterialId', $error->errors());
        }
        $this->assertSame(5, app(StockLedgerService::class)->rawMaterialStockFor(
            StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $cup->id));
        $this->assertDatabaseCount('packaging_wastes', 0);
    }

    public function test_finance_can_search_an_old_classification_beyond_two_hundred_rows(): void
    {
        $ledger = app(StockLedgerService::class);
        $ledger->post('kitchen', $this->kitchen->id, $this->product->id,
            MovementType::PRODUCTION_IN, 220, $this->production->id, $this->kitchen->id);
        $old = $ledger->post('kitchen', $this->kitchen->id, $this->product->id,
            MovementType::WASTE_OUT, 1, $this->production->id, $this->kitchen->id);
        for ($number = 0; $number < 201; $number++) {
            $ledger->post('kitchen', $this->kitchen->id, $this->product->id,
                MovementType::WASTE_OUT, 1, $this->production->id, $this->kitchen->id);
        }
        $options = app(WasteTrackingService::class)->classificationOptions($this->finance, (string) $old->id);
        $this->assertArrayHasKey($old->wasteClassification->id, $options);
        $this->assertSame(202, DB::table('waste_classifications')->count());
        $this->actingAs($this->finance);
        Livewire::test(WasteManagement::class)->call('gotoPage', 7, 'wastePage')
            ->assertSee((string) $old->id);
    }

    public function test_wrong_roles_get_403_for_mutations(): void
    {
        $rider = User::factory()->role(Role::STAFF)->create();
        $service = app(WasteTrackingService::class);
        try {
            $service->recordPromotion($rider, (string) Str::uuid(), $this->kitchen->id,
                $this->product->id, 1, 'No access');
            $this->fail('Rider recorded promotion use.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        try {
            $service->recordPackaging($this->production, (string) Str::uuid(), today()->toDateString(),
                $this->kitchen->id, 1, 1, 500, null);
            $this->fail('Produksi recorded packaging waste.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        try {
            $service->correctReason($this->production, (string) Str::uuid(), 1,
                WasteReason::DAMAGED, 'No access');
            $this->fail('Produksi corrected waste reason.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_cms_report_exports_excel_and_rider_page_is_forbidden(): void
    {
        $this->actingAs($this->finance)->get(WasteManagement::getUrl())->assertOk();
        Livewire::test(WasteManagement::class)->call('exportExcel')
            ->assertFileDownloaded('barang-terbuang-'.today()->format('Y-m').'.xlsx');
        $this->actingAs(User::factory()->role(Role::STAFF)->create())
            ->get(WasteManagement::getUrl())->assertForbidden();
    }

    private function accountAmount(int $movementId, string $code, string $column): int
    {
        return (int) DB::table('journal_entries as j')->join('journal_lines as l', 'l.entry_id', '=', 'j.id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.source_type', 'stock_ledger')->where('j.source_id', $movementId)
            ->where('a.code', $code)->sum('l.'.$column);
    }
}
