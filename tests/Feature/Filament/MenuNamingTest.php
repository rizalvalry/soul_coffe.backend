<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\MenuNaming;
use App\Filament\Pages\StaffActivity;
use App\Filament\Resources\Carts\CartResource;
use App\Filament\Resources\NewsPosts\NewsPostResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Cart;
use App\Models\ModuleLabel;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\Menu\MenuLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renaming a menu, and — far more importantly — everything that must NOT change when you do.
 *
 * The request behind this screen was explicit: names should be editable "tetapi tidak akan
 * menyenggol/membuat bugs terhadap core flow bisnis proses". So the tests that carry the weight
 * here are the negative ones: after a rename, the access matrix still governs the same menu, the
 * URL still resolves, and a role granted the old key still gets in.
 */
class MenuNamingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();
        MenuLabels::forget();

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
    }

    protected function tearDown(): void
    {
        MenuLabels::forget();

        parent::tearDown();
    }

    // ── The rename itself ────────────────────────────────────────────────

    public function test_the_catalogue_covers_every_matrix_module_plus_the_menus_outside_it(): void
    {
        $catalogue = MenuLabels::catalogue();

        foreach (PanelModule::cases() as $module) {
            $this->assertArrayHasKey($module->value, $catalogue, $module->value.' is missing from the naming screen');
        }

        // The three menus that are deliberately outside the access matrix still have names.
        $this->assertArrayHasKey('news_feed', $catalogue);
        $this->assertArrayHasKey('role_matrix', $catalogue);
        $this->assertArrayHasKey('menu_naming', $catalogue);
    }

    public function test_renaming_a_menu_changes_what_the_sidebar_says(): void
    {
        $this->assertSame('Gerobak', CartResource::getNavigationLabel());

        Livewire::actingAs($this->admin)
            ->test(MenuNaming::class)
            ->set('modules.carts', 'Armada')
            ->call('save')
            ->assertHasNoErrors();

        MenuLabels::forget();

        $this->assertSame('Armada', CartResource::getNavigationLabel());
        // Headings and breadcrumbs follow, or the rename would only half apply.
        $this->assertSame('Armada', CartResource::getModelLabel());
        $this->assertSame('Armada', CartResource::getPluralModelLabel());
    }

    public function test_a_page_outside_the_resources_is_renameable_too(): void
    {
        Livewire::actingAs($this->admin)
            ->test(MenuNaming::class)
            ->set('modules.staff_activity', 'Pantau Lapangan')
            ->call('save');

        MenuLabels::forget();

        $this->assertSame('Pantau Lapangan', StaffActivity::getNavigationLabel());
    }

    public function test_the_menus_outside_the_matrix_are_renameable(): void
    {
        Livewire::actingAs($this->admin)
            ->test(MenuNaming::class)
            ->set('modules.news_feed', 'Kabar Tim')
            ->call('save');

        MenuLabels::forget();

        $this->assertSame('Kabar Tim', NewsPostResource::getNavigationLabel());
    }

    public function test_a_navigation_heading_can_be_renamed(): void
    {
        Livewire::actingAs($this->admin)
            ->test(MenuNaming::class)
            ->set('groups.Operasional', 'Harian')
            ->call('save');

        MenuLabels::forget();

        $this->assertSame('Harian', MenuLabels::group('Operasional'));
        $this->assertSame('Harian', SaleResource::getNavigationGroup());
        $this->assertContains('Harian', MenuLabels::groups());
    }

    public function test_blanking_a_name_restores_the_built_in_one(): void
    {
        MenuLabels::set(ModuleLabel::SCOPE_MODULE, 'carts', 'Armada');
        MenuLabels::forget();
        $this->assertSame('Armada', MenuLabels::for(PanelModule::CARTS));

        Livewire::actingAs($this->admin)
            ->test(MenuNaming::class)
            ->set('modules.carts', '')
            ->call('save');

        MenuLabels::forget();

        $this->assertSame('Gerobak', MenuLabels::for(PanelModule::CARTS));
        $this->assertSame(0, ModuleLabel::query()->where('module', 'carts')->count());
    }

    /** Storing a copy of the default would be a row that can silently drift from the code. */
    public function test_typing_the_default_name_stores_nothing(): void
    {
        MenuLabels::set(ModuleLabel::SCOPE_MODULE, 'carts', 'Gerobak');

        $this->assertSame(0, ModuleLabel::query()->count());
    }

    public function test_reset_puts_every_name_back(): void
    {
        MenuLabels::set(ModuleLabel::SCOPE_MODULE, 'carts', 'Armada');
        MenuLabels::set(ModuleLabel::SCOPE_GROUP, 'Operasional', 'Harian');

        Livewire::actingAs($this->admin)
            ->test(MenuNaming::class)
            ->call('resetAll');

        MenuLabels::forget();

        $this->assertSame(0, ModuleLabel::query()->count());
        $this->assertSame('Gerobak', MenuLabels::for(PanelModule::CARTS));
        $this->assertSame('Operasional', MenuLabels::group('Operasional'));
    }

    // ── What a rename must NOT touch ─────────────────────────────────────

    /**
     * The test this whole feature exists to pass.
     *
     * A renamed menu keeps its key, so the permission granted before the rename still governs it
     * afterwards — and the URL it lives at is unchanged, so every bookmark and every link in the
     * panel still resolves.
     */
    public function test_a_rename_does_not_disturb_permissions_or_routes(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        PermissionMatrix::set(Role::FINANCE, PanelModule::CARTS, ['view']);
        PermissionMatrix::forget();

        $urlBefore = CartResource::getUrl();
        $this->actingAs($finance)->get($urlBefore)->assertSuccessful();

        MenuLabels::set(ModuleLabel::SCOPE_MODULE, 'carts', 'Armada Sepeda');
        MenuLabels::forget();

        // Same key in the matrix.
        $this->assertSame(['view'], PermissionMatrix::abilitiesFor(Role::FINANCE, PanelModule::CARTS));
        // Same URL.
        $this->assertSame($urlBefore, CartResource::getUrl());
        // Same access.
        $this->actingAs($finance)->get(CartResource::getUrl())->assertSuccessful();
        // And the enum's own value is untouched, which is what the tests and the code branch on.
        $this->assertSame('carts', PanelModule::CARTS->value);
    }

    public function test_a_rename_does_not_touch_the_records_themselves(): void
    {
        Cart::query()->create(['code' => '0018', 'status' => 'active']);

        MenuLabels::set(ModuleLabel::SCOPE_MODULE, 'carts', 'Armada');
        MenuLabels::forget();

        $this->actingAs($this->admin)
            ->get(CartResource::getUrl())
            ->assertSuccessful()
            // The renamed heading is on the page…
            ->assertSee('Armada')
            // …and the cart itself is still there under it.
            ->assertSee('0018');
    }

    // ── Who may rename ───────────────────────────────────────────────────

    public function test_only_an_administrator_reaches_the_naming_screen(): void
    {
        $this->actingAs($this->admin)->get(MenuNaming::getUrl())->assertSuccessful();

        $finance = User::factory()->role(Role::FINANCE)->create();
        PermissionMatrix::set(Role::FINANCE, PanelModule::CARTS, ['view']);
        PermissionMatrix::forget();

        // Finance can reach the panel, but not this page — the screen that renames every menu,
        // including the one that hands out permissions, is not itself a permission.
        $this->actingAs($finance)->get(MenuNaming::getUrl())->assertForbidden();
    }

    public function test_the_naming_screen_shows_the_immutable_key_beside_each_name(): void
    {
        $this->actingAs($this->admin)
            ->get(MenuNaming::getUrl())
            ->assertSuccessful()
            ->assertSee('carts')
            ->assertSee('sales')
            ->assertSee('Kunci (tidak berubah)');
    }

    /** A cosmetic table must never be able to take the panel down. */
    public function test_names_fall_back_to_the_built_in_ones_when_the_table_is_missing(): void
    {
        \Illuminate\Support\Facades\Schema::drop('module_labels');
        MenuLabels::forget();

        $this->assertSame('Gerobak', MenuLabels::for(PanelModule::CARTS));
        $this->assertSame('Operasional', MenuLabels::group('Operasional'));
        $this->actingAs($this->admin)->get(CartResource::getUrl())->assertSuccessful();
    }
}
