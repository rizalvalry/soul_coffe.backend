<?php

namespace Tests\Feature\Uat;

use App\Enums\Role;
use App\Filament\Pages\AccountingPeriods;
use App\Filament\Pages\GeneralLedger;
use App\Filament\Pages\ManualJournal;
use App\Filament\Pages\TargetCommission;
use App\Filament\Resources\CashTransactions\Pages\CreateCashTransaction;
use App\Filament\Resources\NewsPosts\NewsPostResource;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\AccountingPeriod;
use App\Models\Cart;
use App\Models\NewsPost;
use App\Models\Settlement;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_cms_page_and_resource_renders_for_each_panel_role(): void
    {
        $this->seed();
        $author = User::factory()->role(Role::CONTENT_CREATOR)->create();
        $article = NewsPost::create([
            'title' => 'Kabar Gladi', 'slug' => 'kabar-gladi', 'body' => 'Kabar untuk uji layar.',
            'status' => 'draft', 'author_id' => $author->id,
        ]);
        $panel = Filament::getPanel('admin');
        $resources = $panel->getResources();
        $pages = $panel->getPages();
        $this->assertNotEmpty($resources);
        $this->assertNotEmpty($pages);
        foreach ([[Role::ADMINISTRATOR, true], [Role::ADMINISTRATOR, false],
            [Role::MANAGEMENT, false], [Role::FINANCE, false],
            [Role::BARISTA, false], [Role::CONTENT_CREATOR, false]] as [$role, $super]) {
            $user = User::factory()->role($role)->create(['is_super_admin' => $super]);
            $this->flushSession();
            $this->actingAs($user, 'web');
            foreach ($pages as $page) {
                $status = $this->get($page::getUrl())->status();
                $this->assertContains($status, $page::canAccess() ? [200] : [403, 302], "$role->value $page: $status");
            }
            foreach ($resources as $resource) {
                $record = $resource::getModel()::query()->first();
                foreach ($resource::getPages() as $name => $registeredPage) {
                    if (in_array($name, ['edit', 'view'], true) && ! $record) {
                        continue;
                    }
                    $url = $resource::getUrl($name, in_array($name, ['edit', 'view'], true)
                        ? ['record' => $record] : []);
                    $status = $this->get($url)->status();
                    $allowed = match ($name) {
                        'create' => $resource::canCreate(),
                        'edit' => $resource::canEdit($record),
                        'view' => $resource::canView($record),
                        default => $resource::canViewAny(),
                    };
                    $this->assertContains($status, $allowed ? [200] : [403, 302], "$role->value $resource $name: $status");
                }
            }
        }
        // Hidden pages are part of the registry, even when there is no navigation item.
        foreach ([ManualJournal::class, TargetCommission::class] as $page) {
            $this->assertContains($page, $pages);
        }
        $this->assertContains(StockOpnameResource::class, $resources);
        $this->actingAs($author, 'web')->get(NewsPostResource::getUrl('edit', ['record' => $article]))->assertOk();
        $this->actingAs(User::factory()->role(Role::STAFF)->create(), 'web')
            ->get(NewsPostResource::getUrl('edit', ['record' => $article]))->assertForbidden();
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create(), 'web')
            ->get(GeneralLedger::getUrl())->assertOk();
        $this->actingAs(User::factory()->role(Role::BARISTA)->create(), 'web')
            ->get(GeneralLedger::getUrl())->assertForbidden();
        $this->actingAs(User::factory()->role(Role::STAFF)->create(), 'web')
            ->get(AccountingPeriods::getUrl())->assertForbidden();
    }

    public function test_finance_action_modals_and_cash_and_period_forms_render(): void
    {
        $this->seed();
        $finance = User::factory()->role(Role::FINANCE)->create();
        $cart = Cart::query()->firstOrFail();
        $rider = User::factory()->role(Role::STAFF)->create();
        $settlement = Settlement::create([
            'operating_date' => today(), 'cart_id' => $cart->id, 'staff_id' => $rider->id,
            'status' => 'SUBMITTED', 'cash_minor' => 0, 'qris_minor' => 0,
            'transfer_minor' => 0, 'declared_total_minor' => 0,
        ]);
        Livewire::actingAs($finance)->test(ListSettlements::class)
            ->assertActionVisible('record')->mountAction('record')->assertHasNoErrors();
        Livewire::actingAs($finance)->test(ListSettlements::class)
            ->mountTableAction('approve', $settlement)->assertHasNoErrors();
        $settlement->update(['status' => 'RECONCILED']);
        Livewire::actingAs($finance)->test(CreateCashTransaction::class)
            ->assertSuccessful()->assertFormFieldExists('cash_category_id')
            ->assertFormFieldExists('cash_account_id')->assertFormFieldExists('amount_minor');
        $period = AccountingPeriod::create(['year' => now()->year, 'month' => now()->month, 'status' => 'OPEN']);
        Livewire::actingAs($finance)->test(AccountingPeriods::class)
            ->assertSee('Tutup Periode')->call('closeSelected')->assertHasNoErrors();
        $this->assertSame('CLOSED', $period->fresh()->status);
    }
}
