<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Widgets\CartSalesTodayWidget;
use App\Filament\Widgets\DashboardWelcomeWidget;
use App\Filament\Widgets\OfficeDirectSalesWidget;
use App\Filament\Widgets\OperationsOverviewWidget;
use App\Filament\Widgets\RefillStatusDistributionWidget;
use App\Filament\Widgets\RevenueTrendWidget;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboard renders every widget's getStats()/getData() for real against the database. A
 * widget that throws only when a human opens the panel is exactly the failure mode
 * `AdminResourcesTest`'s docblock warns about for resources — this is that same guarantee for
 * the dashboard.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_with_no_operational_data_yet(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->actingAs($admin)->get(Dashboard::getUrl())->assertSuccessful();
    }

    public function test_a_non_administrator_cannot_reach_the_dashboard(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();

        $this->actingAs($staff)->get('/admin')->assertForbidden();
    }

    public function test_theme_assets_load_on_login_and_dashboard(): void
    {
        $this->get('/admin/login')
            ->assertSuccessful()
            ->assertSee('soul-teal-signature.css', false)
            ->assertSee('soul-teal-filament.css', false)
            ->assertSee('logo-mark.png', false);

        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->actingAs($admin)->get(Dashboard::getUrl())
            ->assertSuccessful()
            ->assertSee('soul-teal-layout.css', false)
            ->assertSee('soulTealChartAxisText', false);
    }

    public function test_dashboard_has_four_kpis_and_a_separate_real_office_sales_card(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin);

        Livewire::test(OperationsOverviewWidget::class)
            ->assertSee('Pendapatan')
            ->assertSee('Selisih Kas')
            ->assertSee('sc-stat-badge--brand', false)
            ->assertDontSee('Penjualan Langsung Kantor');

        Livewire::test(OfficeDirectSalesWidget::class)
            ->assertSee('Penjualan Langsung Kantor')
            ->assertSee('Rp 0')
            ->assertSee('0 transaksi');

        Livewire::test(RevenueTrendWidget::class)
            ->assertSee('Belum ada pendapatan dalam 7 hari terakhir');

        Livewire::test(RefillStatusDistributionWidget::class)
            ->assertSee('Belum ada refill dalam 30 hari terakhir');

        $this->assertLessThan(OfficeDirectSalesWidget::getSort(), RevenueTrendWidget::getSort());
        $this->assertLessThan(RefillStatusDistributionWidget::getSort(), CartSalesTodayWidget::getSort());
        $this->assertSame(1, app(CartSalesTodayWidget::class)->getColumnSpan());
    }

    public function test_panel_roles_keep_their_dashboard_access(): void
    {
        PermissionMatrix::set(Role::FINANCE, PanelModule::DASHBOARD, ['view']);

        foreach ([Role::ADMINISTRATOR, Role::MANAGEMENT, Role::FINANCE, Role::CONTENT_CREATOR] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)->get(Dashboard::getUrl())->assertSuccessful();
        }

        PermissionMatrix::forget();
    }

    public function test_dashboard_greeting_uses_the_signed_in_name_and_existing_reports_gate(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create(['name' => 'Rizal Uji']);
        $this->actingAs($admin);

        Livewire::test(DashboardWelcomeWidget::class)
            ->assertSee('Rizal Uji')
            ->assertSee('Laporan &amp; Ekspor', false);

        $creator = User::factory()->role(Role::CONTENT_CREATOR)->create();
        $this->actingAs($creator);

        Livewire::test(DashboardWelcomeWidget::class)
            ->assertDontSee('Laporan &amp; Ekspor', false);
    }
}
