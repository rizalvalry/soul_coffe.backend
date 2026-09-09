<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\CentralStock;
use App\Filament\Pages\ManageAiSettings;
use App\Filament\Pages\RoleAccessMatrix;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The panel additions requested on 2026-09-09: a central stock page, a fuller employee profile,
 * product photos, the map-backed location form, and the renamed role screen.
 *
 * The profile tests are the ones that matter most. Every new field is optional, and the stated
 * requirement was that a record still saves cleanly with all of them blank — so that is asserted
 * directly rather than assumed from the migration's `nullable()`.
 */
class CmsAdditionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();
        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($this->admin);
    }

    // ── Stok Terpusat ────────────────────────────────────────────────────

    public function test_the_central_stock_page_renders_with_no_stock_at_all(): void
    {
        $this->get(CentralStock::getUrl())->assertSuccessful();
    }

    public function test_the_central_stock_page_shows_its_three_headline_figures(): void
    {
        Livewire::test(CentralStock::class)
            ->assertSuccessful()
            ->assertSee('Total seluruh cups')
            ->assertSee('Masih di dapur')
            ->assertSee('Sudah di gerobak');
    }

    /** Read-only by nature, but still a matrix module: Finance can be given the overview. */
    public function test_central_stock_can_be_granted_to_another_role(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $this->actingAs($finance);

        $this->assertFalse(CentralStock::canAccess());

        PermissionMatrix::set(Role::FINANCE, PanelModule::CENTRAL_STOCK, ['view']);
        PermissionMatrix::forget();

        $this->assertTrue(CentralStock::canAccess());
        $this->get(CentralStock::getUrl())->assertSuccessful();
    }

    // ── Employee profile (#6, #9) ────────────────────────────────────────

    /** The stated requirement: blank optional fields must not block saving a person. */
    public function test_a_user_saves_with_every_optional_profile_field_left_blank(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Tanpa Biodata',
                'phone_e164' => '081298760001',
                'role' => Role::RIDER->value,
                'password' => 'rahasia123',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $saved = User::query()->where('phone_e164', '081298760001')->firstOrFail();

        $this->assertNull($saved->nik);
        $this->assertNull($saved->email);
        $this->assertNull($saved->birth_date);
        $this->assertNull($saved->notes);
        $this->assertNull($saved->bank_account_number);
        // The one profile field with a default, because the absensi sheet needs a number.
        $this->assertSame(4, $saved->monthly_libur_quota);
    }

    public function test_the_full_profile_saves_when_it_is_filled_in(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Lengkap Sekali',
                'phone_e164' => '081298760002',
                'role' => Role::RIDER->value,
                'password' => 'rahasia123',
                'is_active' => true,
                'nik' => '240099',
                'uniform_size' => 'XL',
                'monthly_libur_quota' => 6,
                'email' => 'lengkap@example.com',
                'national_id' => '3171234567890001',
                'birth_date' => '1998-04-17',
                'birth_place' => 'Jakarta',
                'gender' => 'L',
                'marital_status' => 'menikah',
                'address' => 'Jl. Sudirman No. 1',
                'joined_at' => '2026-01-06',
                'emergency_contact_name' => 'Ibu Ani',
                'emergency_contact_phone' => '081200000009',
                'bank_name' => 'BSI',
                'bank_account_number' => '7001234567',
                'bank_account_holder' => 'Lengkap Sekali',
                'notes' => 'Pindah dari gerobak 0018 sejak Maret.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $saved = User::query()->where('phone_e164', '081298760002')->firstOrFail();

        $this->assertSame('240099', $saved->nik);
        $this->assertSame('3171234567890001', $saved->national_id);
        $this->assertSame('1998-04-17', $saved->birth_date->toDateString());
        $this->assertSame('2026-01-06', $saved->joined_at->toDateString());
        $this->assertSame(6, $saved->monthly_libur_quota);
        $this->assertSame('Pindah dari gerobak 0018 sejak Maret.', $saved->notes);
        $this->assertSame('BSI', $saved->bank_name);
    }

    /** NIK is the employee number and must stay unique; KTP is a different column entirely. */
    public function test_a_duplicate_employee_nik_is_refused(): void
    {
        User::factory()->role(Role::RIDER)->create(['nik' => '240001']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Kembar NIK',
                'phone_e164' => '081298760003',
                'role' => Role::RIDER->value,
                'password' => 'rahasia123',
                'nik' => '240001',
            ])
            ->call('create')
            ->assertHasFormErrors(['nik']);
    }

    public function test_editing_a_user_can_add_only_a_note_without_touching_anything_else(): void
    {
        $rider = User::factory()->role(Role::RIDER)->create(['name' => 'Agus']);

        Livewire::test(EditUser::class, ['record' => $rider->getKey()])
            ->fillForm(['notes' => 'Sedang cuti sampai 20 Sept.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $rider->refresh();
        $this->assertSame('Sedang cuti sampai 20 Sept.', $rider->notes);
        $this->assertSame('Agus', $rider->name);
    }

    // ── Product image (#1, CMS side) ─────────────────────────────────────

    public function test_a_product_saves_without_an_image(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'code' => 'TANPA-GAMBAR',
                'name' => 'Tanpa Gambar',
                'unit' => 'cup',
                'sort_order' => 5,
                'is_sellable' => true,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Product::query()->where('code', 'TANPA-GAMBAR')->firstOrFail()->image_path);
    }

    public function test_an_uploaded_product_image_is_stored_and_served_as_an_absolute_url(): void
    {
        Storage::fake('public');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'code' => 'KOPI-FOTO',
                'name' => 'Kopi Berfoto',
                'unit' => 'cup',
                'sort_order' => 1,
                'is_sellable' => true,
                'is_active' => true,
                'image_path' => [UploadedFile::fake()->image('kopi.jpg', 720, 720)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('code', 'KOPI-FOTO')->firstOrFail();

        $this->assertNotNull($product->image_path);
        Storage::disk('public')->assertExists($product->image_path);

        // What the mobile client actually consumes: an absolute URL, or null.
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/products');
        $response->assertSuccessful();

        $row = collect($response->json('data'))->firstWhere('code', 'KOPI-FOTO');
        $this->assertNotNull($row['image_url']);
        $this->assertStringStartsWith('http', $row['image_url']);
    }

    public function test_a_product_without_an_image_reports_null_rather_than_a_broken_url(): void
    {
        Product::create([
            'code' => 'KOSONG', 'name' => 'Kosong', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 2, 'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/products');

        $row = collect($response->json('data'))->firstWhere('code', 'KOSONG');
        $this->assertArrayHasKey('image_url', $row);
        $this->assertNull($row['image_url']);
    }

    // ── Location map (#7) ────────────────────────────────────────────────

    /** The map is a picker, not a store: lat/lng remain the saved fields. */
    public function test_the_location_form_still_saves_the_coordinates_the_map_writes(): void
    {
        Livewire::test(CreateLocation::class)
            ->fillForm([
                'name' => 'Bundaran HI',
                'lat' => '-6.1944491',
                'lng' => '106.8229198',
                'geofence_m' => 120,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('locations', ['name' => 'Bundaran HI', 'geofence_m' => 120]);
    }

    public function test_the_location_create_page_renders_the_map_picker(): void
    {
        $this->get(CreateLocation::getUrl())
            ->assertSuccessful()
            ->assertSee('bsi-map__canvas', escape: false)
            ->assertSee('Cari nama tempat', escape: false);
    }

    // ── Renamed role screen (#8) ─────────────────────────────────────────

    public function test_the_role_screen_is_now_called_management_users_role(): void
    {
        $this->get(RoleAccessMatrix::getUrl())
            ->assertSuccessful()
            ->assertSee('Management Users Role');
    }

    // ── Every switch in the matrix now governs something ─────────────────

    /**
     * The editor used to list modules nothing consulted, so an administrator could tick a box
     * that did nothing. Dashboard and AI settings are wired now; News Feed was removed from the
     * list because its role pairing is the feature.
     */
    public function test_the_dashboard_and_ai_settings_are_really_governed_by_the_matrix(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $this->actingAs($finance);

        $this->assertFalse(ManageAiSettings::canAccess());
        $this->assertFalse(\App\Filament\Widgets\OperationsOverviewWidget::canView());

        PermissionMatrix::set(Role::FINANCE, PanelModule::AI_SETTINGS, ['view']);
        PermissionMatrix::set(Role::FINANCE, PanelModule::DASHBOARD, ['view']);
        PermissionMatrix::forget();

        $this->assertTrue(ManageAiSettings::canAccess());
        $this->assertTrue(\App\Filament\Widgets\OperationsOverviewWidget::canView());
    }

    public function test_the_matrix_no_longer_offers_a_switch_for_the_news_feed(): void
    {
        $values = array_map(fn (PanelModule $m): string => $m->value, PanelModule::cases());

        $this->assertNotContains('news_posts', $values);
    }
}
