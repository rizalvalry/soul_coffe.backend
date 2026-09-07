<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Pages\Reports;
use App\Models\Attendance;
use App\Models\CentralKitchen;
use App\Models\Cart;
use App\Models\Media;
use App\Models\RefillRequest;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Excel exports behind the "Laporan & Ekspor" page. Each test proves a real .xlsx actually
 * leaves the server for the date range on screen — not just that the export class is wired up,
 * since a Livewire action that silently returns nothing looks identical to a working button.
 */
class ReportsExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private CentralKitchen $kitchen;
    private Cart $cart;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Test', 'address' => 'Jl. Uji 1', 'open_at' => '05:00', 'close_at' => '20:00',
        ]);
        $this->cart = Cart::create(['code' => '0001', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $this->staff = User::factory()->role(Role::STAFF)->create();

        $this->actingAs($this->admin);
    }

    public function test_page_renders_with_a_default_thirty_day_range(): void
    {
        $this->get(Reports::getUrl())->assertSuccessful();
    }

    public function test_a_non_administrator_cannot_reach_the_reports_page(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();

        $this->actingAs($staff)->get('/admin/reports')->assertForbidden();
    }

    public function test_export_refills_downloads_an_xlsx_for_the_selected_range(): void
    {
        $media = Media::create([
            'kind' => 'evidence', 'path' => 'evidence/'.Str::uuid().'.jpg', 'mime' => 'image/jpeg',
            'bytes' => 100, 'sha256' => hash('sha256', Str::uuid()->toString()), 'uploaded_by' => $this->staff->id,
        ]);
        RefillRequest::create([
            'uuid' => (string) Str::uuid(), 'code' => 'REF-EXPORT-1',
            'operating_date' => Carbon::today()->toDateString(),
            'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id, 'kitchen_id' => $this->kitchen->id,
            'status' => \App\Enums\RefillStatus::CLOSED, 'evidence_photo_id' => $media->id,
            'submitted_at' => now(),
        ]);

        Livewire::test(Reports::class)
            ->fillForm(['from' => Carbon::today()->toDateString(), 'to' => Carbon::today()->toDateString()])
            ->call('exportRefills')
            ->assertFileDownloaded(sprintf('refill-requests_%s_%s.xlsx', Carbon::today()->toDateString(), Carbon::today()->toDateString()));
    }

    public function test_export_revenue_downloads_an_xlsx(): void
    {
        Settlement::query()->create([
            'operating_date' => Carbon::today()->toDateString(),
            'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id, 'declared_total_minor' => 100_000,
        ]);

        Livewire::test(Reports::class)
            ->fillForm(['from' => Carbon::today()->toDateString(), 'to' => Carbon::today()->toDateString()])
            ->call('exportRevenue')
            ->assertFileDownloaded(sprintf('pendapatan_%s_%s.xlsx', Carbon::today()->toDateString(), Carbon::today()->toDateString()));
    }

    public function test_export_attendance_downloads_an_xlsx(): void
    {
        Attendance::create([
            'operating_date' => Carbon::today(), 'user_id' => $this->staff->id,
            'role' => Role::STAFF, 'clocked_in_at' => now(),
        ]);

        Livewire::test(Reports::class)
            ->fillForm(['from' => Carbon::today()->toDateString(), 'to' => Carbon::today()->toDateString()])
            ->call('exportAttendance')
            ->assertFileDownloaded(sprintf('kehadiran_%s_%s.xlsx', Carbon::today()->toDateString(), Carbon::today()->toDateString()));
    }

    /** A range typed backwards is a validation error, not a silently empty file. */
    public function test_a_reversed_date_range_fails_validation_instead_of_exporting(): void
    {
        Livewire::test(Reports::class)
            ->fillForm(['from' => Carbon::today()->toDateString(), 'to' => Carbon::today()->subDays(5)->toDateString()])
            ->call('exportRevenue')
            ->assertHasErrors(['data.to'])
            ->assertNoFileDownloaded();
    }
}
