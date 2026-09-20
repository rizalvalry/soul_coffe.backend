<?php

namespace Tests\Feature\Filament;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\CentralStock;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stok Terpusat berhenti menjadi layar baca-saja: satu tombol penyesuaian kini memposting koreksi
 * ke buku besar. Test yang paling penting adalah pemberian hak read-only — sama seperti yang sudah
 * dijaga SalesTable dan StockOpnamesTable — karena tombol ini menulis ke stok.
 */
class CentralStockPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CentralKitchen $kitchen;

    private Product $coffee;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);

        $this->coffee = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
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

    // ── Modul tidak lagi read-only ───────────────────────────────────────

    public function test_the_module_is_no_longer_read_only(): void
    {
        $this->assertFalse(PanelModule::CENTRAL_STOCK->isReadOnly());
    }

    // ── Akses halaman ────────────────────────────────────────────────────

    public function test_an_administrator_sees_the_page(): void
    {
        $this->seedKitchenStock(40);

        $this->actingAs($this->admin)
            ->get(CentralStock::getUrl())
            ->assertSuccessful()
            ->assertSee('Dapur Uji');
    }

    public function test_a_role_without_the_view_ability_is_refused(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        $this->actingAs($finance)->get(CentralStock::getUrl())->assertForbidden();
    }

    // ── Tombol penyesuaian mengikuti matriks ─────────────────────────────

    public function test_view_only_does_not_get_the_adjustment_action(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        PermissionMatrix::set(Role::FINANCE, PanelModule::CENTRAL_STOCK, ['view']);
        PermissionMatrix::forget();

        Livewire::actingAs($finance)
            ->test(CentralStock::class)
            ->assertActionHidden('stockAdjustment');
    }

    public function test_the_edit_ability_reveals_the_adjustment_action(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        PermissionMatrix::set(Role::FINANCE, PanelModule::CENTRAL_STOCK, ['view', 'edit']);
        PermissionMatrix::forget();

        Livewire::actingAs($finance)
            ->test(CentralStock::class)
            ->assertActionVisible('stockAdjustment');
    }

    // ── Aksi benar-benar memposting ──────────────────────────────────────

    public function test_submitting_the_action_posts_an_adjustment_to_the_ledger(): void
    {
        $this->seedKitchenStock(40);

        Livewire::actingAs($this->admin)
            ->test(CentralStock::class)
            ->callAction('stockAdjustment', [
                'location_kind' => StockLedgerService::KITCHEN,
                'location_id' => $this->kitchen->id,
                'product_id' => $this->coffee->id,
                'counted_qty' => 45,
                'reason' => 'Hitung ulang sore, ada krat terlewat',
            ]);

        $this->assertDatabaseHas('stock_ledger', [
            'movement_type' => MovementType::ADJUSTMENT->value,
            'qty_delta' => 5,
            'ref_type' => 'stock_adjustment',
            'note' => 'Hitung ulang sore, ada krat terlewat',
        ]);

        $this->assertSame(
            45,
            app(StockLedgerService::class)->stockFor(StockLedgerService::KITCHEN, $this->kitchen->id, $this->coffee->id),
        );
    }

    public function test_a_rejected_adjustment_posts_nothing(): void
    {
        $this->seedKitchenStock(40);

        Livewire::actingAs($this->admin)
            ->test(CentralStock::class)
            ->callAction('stockAdjustment', [
                'location_kind' => StockLedgerService::KITCHEN,
                'location_id' => $this->kitchen->id,
                'product_id' => $this->coffee->id,
                // Sama dengan stok sistem: service menolak, dan layar hanya memberi tahu.
                'counted_qty' => 40,
                'reason' => 'Mencoba menyesuaikan padahal sama',
            ]);

        $this->assertSame(1, StockLedger::query()->count());
    }
}
