<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Carts\CartResource;
use App\Filament\Resources\CentralKitchens\CentralKitchenResource;
use App\Filament\Resources\DailyTargets\DailyTargetResource;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StaffAssignments\StaffAssignmentResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'penugasan staff' => [StaffAssignmentResource::class],
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
}
