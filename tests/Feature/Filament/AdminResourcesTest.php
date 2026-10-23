<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Carts\CartResource;
use App\Filament\Resources\Carts\Pages\ViewCart;
use App\Filament\Resources\Carts\RelationManagers\StaffAssignmentsRelationManager;
use App\Filament\Resources\CentralKitchens\CentralKitchenResource;
use App\Filament\Resources\DailyTargets\DailyTargetResource;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Location;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every admin screen is reachable and renders. A resource that throws only when someone opens
 * it is a resource nobody has actually tested.
 */
class AdminResourcesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->admin = User::where('role', Role::ADMINISTRATOR)->firstOrFail();
        $this->actingAs($this->admin);
    }

    public static function resourceProvider(): array
    {
        return [
            'pengguna' => [UserResource::class],
            'produk' => [ProductResource::class],
            'gerobak' => [CartResource::class],
            'lokasi' => [LocationResource::class],
            'dapur pusat' => [CentralKitchenResource::class],
            'target harian' => [DailyTargetResource::class],
            'audit trail' => [AuditLogResource::class],
        ];
    }

    #[DataProvider('resourceProvider')]
    public function test_index_page_renders(string $resource): void
    {
        $this->get($resource::getUrl('index'))->assertSuccessful();
    }

    #[DataProvider('resourceProvider')]
    public function test_create_page_renders_where_creation_is_allowed(string $resource): void
    {
        if (! $resource::canCreate()) {
            $this->assertArrayNotHasKey('create', $resource::getPages());

            return;
        }

        $this->get($resource::getUrl('create'))->assertSuccessful();
    }

    public function test_audit_trail_is_read_only(): void
    {
        $log = AuditLog::create([
            'actor_id' => $this->admin->id,
            'actor_role' => Role::ADMINISTRATOR->value,
            'action' => 'test.action',
            'subject_type' => User::class,
            'subject_id' => $this->admin->id,
            'before_json' => ['is_active' => true],
            'after_json' => ['is_active' => false],
        ]);

        $this->assertFalse(AuditLogResource::canCreate());
        $this->assertFalse(AuditLogResource::canEdit($log));
        $this->assertFalse(AuditLogResource::canDelete($log));

        // Reading it, however, must work — that is the entire point of the screen.
        $this->get(AuditLogResource::getUrl('view', ['record' => $log]))->assertSuccessful();
    }

    public function test_finance_sees_a_readable_negative_stock_marker(): void
    {
        $log = AuditLog::create([
            'actor_id' => $this->admin->id,
            'actor_role' => Role::ADMINISTRATOR->value,
            'action' => 'stock_ledger.negative_balance',
            'subject_type' => User::class,
            'subject_id' => $this->admin->id,
            'after_json' => ['balance_qty' => -2],
        ]);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());

        $this->get(AuditLogResource::getUrl('index'))->assertSuccessful()->assertSee('Stok minus tercatat');
        $this->get(AuditLogResource::getUrl('view', ['record' => $log]))
            ->assertSuccessful()->assertSee('Stok minus tercatat');
    }

    public function test_old_assignment_resource_url_is_gone_for_every_role(): void
    {
        $this->assertDatabaseCount('staff_assignments', 0);
        $this->assertFalse(Route::has('filament.admin.resources.staff-assignments.index'));
        $this->get('/admin/staff-assignments')->assertNotFound();

        foreach ([Role::STAFF, Role::RIDER, Role::BARISTA, Role::FINANCE, Role::MANAGEMENT, Role::CONTENT_CREATOR] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->get('/admin/staff-assignments')->assertNotFound();
        }
    }

    public function test_coffee_bike_history_is_read_only_and_scoped_to_its_record(): void
    {
        $cart = Cart::query()->create(['code' => 'T046-A', 'status' => 'active']);
        $otherCart = Cart::query()->create(['code' => 'T046-B', 'status' => 'active']);
        $location = Location::query()->firstOrFail();
        $rider = User::factory()->role(Role::STAFF)->create();
        $handover = StaffAssignment::query()->create([
            'user_id' => $rider->id,
            'cart_id' => $cart->id,
            'operating_date' => today()->toDateString(),
            'source' => 'hand_to_cart',
            'assigned_by' => $this->admin->id,
            'location_id' => $location->id,
        ]);
        $other = StaffAssignment::query()->create([
            'user_id' => $rider->id,
            'cart_id' => $otherCart->id,
            'operating_date' => today()->subDay()->toDateString(),
            'source' => 'manual',
            'assigned_by' => $this->admin->id,
            'location_id' => $location->id,
        ]);

        $this->get(CartResource::getUrl('view', ['record' => $cart]))->assertOk();
        $this->assertContains(StaffAssignmentsRelationManager::class, CartResource::getRelations());
        Livewire::test(StaffAssignmentsRelationManager::class, [
            'ownerRecord' => $cart, 'pageClass' => ViewCart::class,
        ])->assertCanSeeTableRecords([$handover])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('Add Stock')
            ->assertSee('Riwayat pemegang Coffee Bike')
            ->assertTableActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');

        $this->assertSame(2, StaffAssignment::query()->whereIn('id', [$handover->id, $other->id])->count());
    }

    public function test_coffee_bike_history_denies_wrong_role_and_revoked_grant(): void
    {
        $cart = Cart::query()->firstOrFail();
        $finance = User::factory()->role(Role::FINANCE)->create();
        PermissionMatrix::set(Role::FINANCE, PanelModule::CARTS, []);
        $this->actingAs($finance);

        $this->assertFalse(StaffAssignmentsRelationManager::canViewForRecord($cart, ViewCart::class));
        $this->get(CartResource::getUrl('view', ['record' => $cart]))->assertForbidden();

        PermissionMatrix::set(Role::FINANCE, PanelModule::CARTS, ['view']);
        PermissionMatrix::forget();
        $this->assertTrue(StaffAssignmentsRelationManager::canViewForRecord($cart, ViewCart::class));

        PermissionMatrix::set(Role::FINANCE, PanelModule::CARTS, []);
        PermissionMatrix::forget();
        $this->assertFalse(StaffAssignmentsRelationManager::canViewForRecord($cart, ViewCart::class));

    }

    public function test_staff_assignment_carry_forward_is_not_registered_or_scheduled(): void
    {
        Artisan::call('schedule:list');
        $this->assertArrayNotHasKey('soul:carry-forward-staff-assignments', Artisan::all());
        $this->assertFalse(collect(Schedule::events())
            ->contains(fn ($event): bool => str_contains($event->command, 'soul:carry-forward-staff-assignments')));
    }

    public function test_management_actor_uses_its_label_in_the_audit_table_and_detail(): void
    {
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $log = AuditLog::create([
            'actor_id' => $management->id,
            'actor_role' => Role::MANAGEMENT->value,
            'action' => 'test.management',
            'subject_type' => User::class,
            'subject_id' => $management->id,
        ]);

        $this->get(AuditLogResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSeeText('Management')
            ->assertDontSeeText('MANAGEMENT');

        $this->get(AuditLogResource::getUrl('view', ['record' => $log]))
            ->assertSuccessful()
            ->assertSeeText('Management')
            ->assertDontSeeText('MANAGEMENT');
    }
}
