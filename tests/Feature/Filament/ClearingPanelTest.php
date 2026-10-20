<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\ClearingReceipts\ClearingReceiptResource;
use App\Filament\Resources\ClearingReceipts\Pages\CreateClearingReceipt;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ClearingReceipt;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ClearingPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountSeeder::class);
        PermissionMatrix::forget();
        PermissionMatrix::set(Role::FINANCE, PanelModule::ATTENDANCE, ['view']);
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::ATTENDANCE, ['view']);
    }

    public function test_finance_and_management_can_open_clearing_pages_and_finance_can_record(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        foreach ([$finance, $management] as $actor) {
            $this->actingAs($actor);
            $this->get(ClearingReceiptResource::getUrl())->assertSuccessful()->assertSee('Umur saldo kliring');
            $this->get(ClearingReceiptResource::getUrl('create'))->assertSuccessful();
        }

        $this->actingAs($finance);
        Livewire::test(CreateClearingReceipt::class)
            ->fillForm([
                'uuid' => (string) Str::uuid(),
                'received_on' => today()->toDateString(),
                'amount_minor' => 900,
                'mdr_minor' => 100,
                'bank_account_id' => Account::query()->where('code', '1-1300')->value('id'),
                'sale_dates' => [],
            ])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(900, ClearingReceipt::query()->sole()->amount_minor);
        $this->assertSame(1, AuditLog::query()->where('action', 'clearing_receipt.created')->count());
    }

    public function test_wrong_role_cannot_open_clearing_pages(): void
    {
        $this->actingAs(User::factory()->role(Role::STAFF)->create());
        $this->get(ClearingReceiptResource::getUrl())->assertForbidden();
        $this->get(ClearingReceiptResource::getUrl('create'))->assertForbidden();
    }
}
