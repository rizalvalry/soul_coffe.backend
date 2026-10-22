<?php

namespace Tests\Feature\Customer;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerCmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_management_and_administrator_can_view_and_manage_customers(): void
    {
        $customer = Customer::create([
            'google_sub' => 'cms-google', 'name' => 'Ayu', 'email' => 'ayu@example.test',
        ]);
        $customer->createToken('before-disable', ['customer']);
        foreach ([Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor);
            $this->assertTrue(PermissionMatrix::can($actor, PanelModule::CUSTOMERS, 'view'));
            $this->assertTrue(PermissionMatrix::can($actor, PanelModule::CUSTOMERS, 'edit'));
            $this->get(CustomerResource::getUrl('index'))->assertSuccessful()
                ->assertSee('Ayu')->assertSee('a***@example.test')->assertDontSee('ayu@example.test');
            $this->assertFalse(CustomerResource::canCreate());
        }

        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        Livewire::test(ListCustomers::class)
            ->callTableAction('toggleStatus', $customer, ['reason' => 'Akun perlu dicek'])
            ->assertHasNoErrors();
        $this->assertSame('DISABLED', $customer->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $audit = AuditLog::query()->where('action', 'customer.status_changed')->firstOrFail();
        $this->assertSame('Akun perlu dicek', $audit->after_json['reason']);
        Livewire::test(ListCustomers::class)
            ->callTableAction('toggleStatus', $customer->fresh(), ['reason' => 'Sudah dicek'])
            ->assertHasNoErrors();
        $this->assertSame('ACTIVE', $customer->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_other_roles_cannot_open_customer_menu_or_change_status(): void
    {
        $customer = Customer::create(['google_sub' => 'cms-google', 'name' => 'Ayu']);
        foreach ([Role::BARISTA, Role::STAFF, Role::RIDER, Role::CONTENT_CREATOR] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor);
            $this->assertFalse(CustomerResource::canViewAny());
            $this->assertFalse(PermissionMatrix::can($actor, PanelModule::CUSTOMERS, 'edit'));
            $this->get(CustomerResource::getUrl('index'))->assertForbidden();
        }
        $this->assertSame('ACTIVE', $customer->fresh()->status);
        $this->assertDatabaseMissing('audit_log', ['action' => 'customer.status_changed']);
    }

    public function test_each_allowed_role_can_disable_with_an_audited_reason(): void
    {
        foreach ([Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR] as $role) {
            $customer = Customer::create(['google_sub' => 'cms-'.$role->value]);
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor);
            Livewire::test(ListCustomers::class)
                ->callTableAction('toggleStatus', $customer, ['reason' => 'Verifikasi akun'])
                ->assertHasNoErrors();

            $this->assertSame('DISABLED', $customer->fresh()->status);
            $this->assertDatabaseHas('audit_log', [
                'actor_id' => $actor->id,
                'action' => 'customer.status_changed',
                'subject_id' => $customer->id,
            ]);
        }
    }
}
