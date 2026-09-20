<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\StockAdjustmentService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Penyesuaian stok dari layar Stok Terpusat — koreksi satu sel yang jelas salah.
 *
 * Test yang paling penting dibaca lebih dulu adalah
 * `test_the_delta_is_measured_against_live_stock_not_the_figure_on_screen`. Itulah alasan selisih
 * dihitung ulang di dalam transaksi terhadap stok yang dikunci, bukan dari angka yang kebetulan
 * sedang tampil di grid: penjualan yang terjadi antara operator membaca layar dan menekan tombol
 * tidak boleh ikut terhapus oleh koreksi yang sudah basi.
 */
class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Product $coffee;

    private User $admin;

    private User $finance;

    private User $barista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $this->cart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);

        $this->coffee = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
    }

    private function service(): StockAdjustmentService
    {
        return app(StockAdjustmentService::class);
    }

    private function stockOf(string $type, int $locationId): int
    {
        return app(StockLedgerService::class)->stockFor($type, $locationId, $this->coffee->id);
    }

    private function seedKitchenStock(int $qty): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::KITCHEN,
            locationId: $this->kitchen->id,
            productId: $this->coffee->id,
            movementType: MovementType::PRODUCTION_IN,
            qty: $qty,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
        );
    }

    // ── Arah koreksi ─────────────────────────────────────────────────────

    public function test_adjusting_up_posts_a_positive_adjustment_and_lands_on_the_counted_figure(): void
    {
        $this->seedKitchenStock(40);

        $row = $this->service()->adjust(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            $this->coffee->id,
            45,
            'Hitung ulang sore, ada krat terlewat',
            $this->admin,
        );

        $this->assertSame(MovementType::ADJUSTMENT, $row->movement_type);
        $this->assertSame(5, $row->qty_delta);
        $this->assertSame(45, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id));
    }

    public function test_adjusting_down_posts_a_negative_adjustment_and_lands_on_the_counted_figure(): void
    {
        $this->seedKitchenStock(40);

        $row = $this->service()->adjust(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            $this->coffee->id,
            32,
            'Delapan cup tumpah dan belum tercatat',
            $this->finance,
        );

        $this->assertSame(-8, $row->qty_delta);
        $this->assertSame(32, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id));
    }

    public function test_the_original_movements_are_never_rewritten(): void
    {
        $this->seedKitchenStock(40);

        $this->service()->adjust(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            $this->coffee->id,
            45,
            'Hitung ulang sore hari',
            $this->admin,
        );

        // Baris produksi aslinya harus utuh: koreksi adalah baris baru, bukan penimpaan.
        $this->assertDatabaseHas('stock_ledger', [
            'movement_type' => MovementType::PRODUCTION_IN->value,
            'qty_delta' => 40,
        ]);
        $this->assertSame(2, StockLedger::query()->count());
    }

    // ── Invarian inti ────────────────────────────────────────────────────

    public function test_the_delta_is_measured_against_live_stock_not_the_figure_on_screen(): void
    {
        $this->seedKitchenStock(40);

        // Operator membaca 40 di layar, lalu sesuatu bergerak sebelum tombol ditekan.
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::KITCHEN,
            locationId: $this->kitchen->id,
            productId: $this->coffee->id,
            movementType: MovementType::WASTE_OUT,
            qty: 10,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
        );

        $this->service()->adjust(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            $this->coffee->id,
            45,
            'Hitung fisik sore, seharusnya 45',
            $this->admin,
        );

        // Bila selisih dihitung dari angka layar (40), stok akan mendarat di 35. Yang benar 45.
        $this->assertSame(45, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id));
    }

    public function test_a_figure_equal_to_current_stock_is_refused_and_posts_nothing(): void
    {
        $this->seedKitchenStock(40);

        try {
            $this->service()->adjust(
                StockLedgerService::KITCHEN,
                $this->kitchen->id,
                $this->coffee->id,
                40,
                'Mencoba menyesuaikan padahal sama',
                $this->admin,
            );
            $this->fail('Penyesuaian tanpa selisih seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah sama', $e->getMessage());
        }

        $this->assertSame(1, StockLedger::query()->count());
    }

    // ── Validasi masukan ─────────────────────────────────────────────────

    public function test_a_negative_counted_figure_is_refused(): void
    {
        $this->seedKitchenStock(40);

        $this->expectException(RuntimeException::class);

        $this->service()->adjust(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            $this->coffee->id,
            -1,
            'Alasan yang cukup panjang',
            $this->admin,
        );
    }

    public function test_an_empty_reason_is_refused(): void
    {
        $this->seedKitchenStock(40);

        $this->expectException(RuntimeException::class);

        $this->service()->adjust(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            $this->coffee->id,
            45,
            '   ',
            $this->admin,
        );
    }

    public function test_a_reason_shorter_than_ten_characters_is_refused(): void
    {
        $this->seedKitchenStock(40);

        try {
            $this->service()->adjust(
                StockLedgerService::KITCHEN,
                $this->kitchen->id,
                $this->coffee->id,
                45,
                'salah',
                $this->admin,
            );
            $this->fail('Alasan terlalu pendek seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('10 karakter', $e->getMessage());
        }

        $this->assertSame(1, StockLedger::query()->count());
    }

    public function test_the_reason_is_recorded_on_the_posted_row(): void
    {
        $this->seedKitchenStock(40);

        $row = $this->service()->adjust(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            $this->coffee->id,
            45,
            'Lima cup ditemukan di chiller kedua',
            $this->admin,
        );

        $this->assertSame('Lima cup ditemukan di chiller kedua', $row->note);
        $this->assertSame('stock_adjustment', $row->ref_type);
    }

    // ── Siapa yang boleh ─────────────────────────────────────────────────

    public function test_finance_may_adjust(): void
    {
        $this->seedKitchenStock(40);

        $row = $this->service()->adjust(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            $this->coffee->id,
            41,
            'Satu cup ditemukan kembali',
            $this->finance,
        );

        $this->assertSame(1, $row->qty_delta);
    }

    public function test_a_barista_may_not_adjust(): void
    {
        $this->seedKitchenStock(40);

        try {
            $this->service()->adjust(
                StockLedgerService::KITCHEN,
                $this->kitchen->id,
                $this->coffee->id,
                45,
                'Mencoba dari peran yang tidak berhak',
                $this->barista,
            );
            $this->fail('Barista seharusnya tidak berhak menyesuaikan stok.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Administrator atau Finance', $e->getMessage());
        }

        $this->assertSame(40, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id));
    }

    // ── Lokasi dan produk ────────────────────────────────────────────────

    public function test_a_cart_can_be_adjusted_too(): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::CART,
            locationId: $this->cart->id,
            productId: $this->coffee->id,
            movementType: MovementType::REFILL_IN,
            qty: 20,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
        );

        $row = $this->service()->adjust(
            StockLedgerService::CART,
            $this->cart->id,
            $this->coffee->id,
            18,
            'Dua cup rusak di perjalanan',
            $this->admin,
        );

        $this->assertSame(-2, $row->qty_delta);
        $this->assertSame($this->kitchen->id, $row->kitchen_id);
        $this->assertSame(18, $this->stockOf(StockLedgerService::CART, $this->cart->id));
    }

    public function test_a_product_with_no_ledger_row_yet_starts_from_zero(): void
    {
        $row = $this->service()->adjust(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            $this->coffee->id,
            12,
            'Stok awal yang belum pernah tercatat',
            $this->admin,
        );

        $this->assertSame(12, $row->qty_delta);
        $this->assertSame(12, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id));
    }

    public function test_an_inactive_product_is_refused(): void
    {
        $retired = Product::create([
            'code' => 'LAMA', 'name' => 'Produk Lama', 'unit' => 'cup',
            'is_sellable' => false, 'sort_order' => 9, 'is_active' => false,
        ]);

        $this->expectException(RuntimeException::class);

        $this->service()->adjust(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            $retired->id,
            5,
            'Alasan yang cukup panjang untuk lolos',
            $this->admin,
        );
    }

    public function test_an_unknown_location_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->adjust(
            StockLedgerService::KITCHEN,
            9999,
            $this->coffee->id,
            5,
            'Alasan yang cukup panjang untuk lolos',
            $this->admin,
        );
    }

    // ── Bahan baku ───────────────────────────────────────────────────────

    private function milk(?int $reorderPoint = null): \App\Models\RawMaterial
    {
        return \App\Models\RawMaterial::create([
            'code' => 'SUSU', 'name' => 'Susu', 'unit' => 'ml',
            'reorder_point' => $reorderPoint, 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function receiveMilk(int $rawMaterialId, int $qty): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::PURCHASE_IN,
            qty: $qty,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
            rawMaterialId: $rawMaterialId,
        );
    }

    private function milkStock(int $rawMaterialId): int
    {
        return app(StockLedgerService::class)->rawMaterialStockFor(
            StockLedgerService::RAW_MATERIAL_STORE,
            $this->kitchen->id,
            $rawMaterialId,
        );
    }

    public function test_a_raw_material_can_be_adjusted_down(): void
    {
        $milk = $this->milk();
        $this->receiveMilk($milk->id, 5000);

        $row = $this->service()->adjustRawMaterial(
            $this->kitchen->id,
            $milk->id,
            4200,
            'Sisa di jerigen ternyata kurang',
            $this->admin,
        );

        $this->assertSame(-800, $row->qty_delta);
        $this->assertSame($milk->id, $row->raw_material_id);
        $this->assertNull($row->product_id);
        $this->assertSame(4200, $this->milkStock($milk->id));
    }

    public function test_a_raw_material_can_be_adjusted_up(): void
    {
        $milk = $this->milk();
        $this->receiveMilk($milk->id, 1000);

        $row = $this->service()->adjustRawMaterial(
            $this->kitchen->id,
            $milk->id,
            1500,
            'Ada satu karton yang belum tercatat',
            $this->admin,
        );

        $this->assertSame(500, $row->qty_delta);
        $this->assertSame(1500, $this->milkStock($milk->id));
    }

    public function test_a_raw_material_adjustment_is_measured_against_live_stock(): void
    {
        $milk = $this->milk();
        $this->receiveMilk($milk->id, 5000);

        // Konsumsi terjadi setelah operator membaca layar.
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::RECIPE_CONSUME_OUT,
            qty: 1000,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
            rawMaterialId: $milk->id,
        );

        $this->service()->adjustRawMaterial(
            $this->kitchen->id,
            $milk->id,
            4500,
            'Hitung fisik jerigen sore hari',
            $this->admin,
        );

        $this->assertSame(4500, $this->milkStock($milk->id));
    }

    public function test_a_raw_material_figure_equal_to_stock_is_refused(): void
    {
        $milk = $this->milk();
        $this->receiveMilk($milk->id, 5000);

        $this->expectException(RuntimeException::class);

        $this->service()->adjustRawMaterial(
            $this->kitchen->id,
            $milk->id,
            5000,
            'Mencoba menyesuaikan padahal sama',
            $this->admin,
        );
    }

    public function test_a_barista_may_not_adjust_a_raw_material(): void
    {
        $milk = $this->milk();
        $this->receiveMilk($milk->id, 5000);

        $this->expectException(RuntimeException::class);

        $this->service()->adjustRawMaterial(
            $this->kitchen->id,
            $milk->id,
            4000,
            'Mencoba dari peran yang tidak berhak',
            $this->barista,
        );
    }

    public function test_a_raw_material_reason_is_recorded_and_too_short_is_refused(): void
    {
        $milk = $this->milk();
        $this->receiveMilk($milk->id, 5000);

        try {
            $this->service()->adjustRawMaterial($this->kitchen->id, $milk->id, 4000, 'kurang', $this->admin);
            $this->fail('Alasan terlalu pendek seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('10 karakter', $e->getMessage());
        }

        $row = $this->service()->adjustRawMaterial(
            $this->kitchen->id,
            $milk->id,
            4000,
            'Tumpah saat pemindahan jerigen',
            $this->admin,
        );

        $this->assertSame('Tumpah saat pemindahan jerigen', $row->note);
        $this->assertSame('stock_adjustment', $row->ref_type);
    }

    public function test_an_inactive_raw_material_is_refused(): void
    {
        $retired = \App\Models\RawMaterial::create([
            'code' => 'LAMA', 'name' => 'Bahan Lama', 'unit' => 'g',
            'is_active' => false, 'sort_order' => 9,
        ]);

        $this->expectException(RuntimeException::class);

        $this->service()->adjustRawMaterial(
            $this->kitchen->id,
            $retired->id,
            10,
            'Alasan yang cukup panjang untuk lolos',
            $this->admin,
        );
    }

    public function test_adjusting_a_raw_material_never_touches_finished_goods(): void
    {
        $this->seedKitchenStock(40);
        $milk = $this->milk();
        $this->receiveMilk($milk->id, 5000);

        $this->service()->adjustRawMaterial(
            $this->kitchen->id,
            $milk->id,
            4000,
            'Koreksi hitung fisik bahan baku',
            $this->admin,
        );

        $this->assertSame(40, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id));
    }
}
