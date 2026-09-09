<?php

namespace Tests\Feature\Filament;

use App\Enums\IncidentStatus;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\DeliveryIncidents\DeliveryIncidentResource;
use App\Filament\Resources\DeliveryIncidents\Pages\ListDeliveryIncidents;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\DeliveryIncident;
use App\Models\DeliveryIncidentLine;
use App\Models\Media;
use App\Models\Product;
use App\Models\RefillRequest;
use App\Models\RefillRequestLine;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The decision desk in the panel.
 *
 * The test that matters is the read-only grant one. Two of these buttons write to the stock
 * ledger and cancel somebody's delivery, so a role that was given "Lihat" and nothing else must
 * not be able to press them — and the service refuses again underneath, because a hidden button
 * is not an authorisation.
 */
class DeliveryIncidentPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DeliveryIncident $incident;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();

        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $cart = Cart::create(['code' => '0099', 'status' => 'active', 'kitchen_id' => $kitchen->id]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $staff = User::factory()->role(Role::STAFF)->create(['name' => 'Mufit']);
        $rider = User::factory()->role(Role::RIDER)->create(['name' => 'Agung']);

        $product = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);

        $evidence = Media::create([
            'kind' => 'evidence',
            'path' => 'evidence/'.Str::uuid().'.jpg',
            'mime' => 'image/jpeg',
            'bytes' => 2048,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'exif_taken_at' => now(),
            'uploaded_by' => $staff->id,
        ]);

        // Written directly: this test is about the panel, and the flow that produces an incident
        // is proven end to end in DeliveryIncidentTest.
        $refill = RefillRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => 'RF-UJI-1',
            'operating_date' => Carbon::today(),
            'cart_id' => $cart->id,
            'staff_id' => $staff->id,
            'kitchen_id' => $kitchen->id,
            'rider_id' => $rider->id,
            'evidence_photo_id' => $evidence->id,
            'status' => 'PICKED_UP',
            'version' => 1,
        ]);

        $line = RefillRequestLine::query()->create([
            'refill_request_id' => $refill->id,
            'product_id' => $product->id,
            'qty_requested' => 10,
            'qty_approved' => 10,
            'qty_prepared' => 10,
            // Pinned at submit in the real flow (R10); supplied here because the column is not
            // nullable and this fixture bypasses the flow.
            'unit_cost_minor' => 8000,
            'line_cost_minor' => 80000,
        ]);

        $photo = Media::create([
            'kind' => 'incident',
            'path' => 'incidents/'.Str::uuid().'.jpg',
            'mime' => 'image/jpeg',
            'bytes' => 2048,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'exif_taken_at' => now(),
            'uploaded_by' => $rider->id,
        ]);

        $this->incident = DeliveryIncident::query()->create([
            'uuid' => (string) Str::uuid(),
            'refill_request_id' => $refill->id,
            'rider_id' => $rider->id,
            'photo_media_id' => $photo->id,
            'reported_at' => now(),
            'note' => 'Jatuh di Jalan Pemuda.',
            'status' => IncidentStatus::REPORTED,
        ]);

        DeliveryIncidentLine::query()->create([
            'delivery_incident_id' => $this->incident->id,
            'refill_request_line_id' => $line->id,
            'product_id' => $product->id,
            'qty_damaged' => 4,
        ]);
    }

    public function test_an_administrator_sees_the_open_incident(): void
    {
        $this->actingAs($this->admin)
            ->get(DeliveryIncidentResource::getUrl())
            ->assertSuccessful()
            ->assertSee('Agung')
            ->assertSee('RF-UJI-1');
    }

    public function test_the_menu_badge_counts_incidents_waiting_for_a_decision(): void
    {
        $this->actingAs($this->admin);

        $this->assertSame('1', DeliveryIncidentResource::getNavigationBadge());

        $this->incident->update(['status' => IncidentStatus::RESOLVED_PARTIAL]);

        $this->assertNull(DeliveryIncidentResource::getNavigationBadge());
    }

    public function test_deciding_partially_from_the_panel_writes_off_the_cups(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListDeliveryIncidents::class)
            ->callTableAction('partial', $this->incident, ['note' => 'Sisanya masih layak.'])
            ->assertHasNoTableActionErrors();

        $this->incident->refresh();
        $this->assertSame(IncidentStatus::RESOLVED_PARTIAL, $this->incident->status);
        $this->assertSame(4, $this->incident->written_off_qty);
        $this->assertSame($this->admin->id, $this->incident->decided_by);
    }

    public function test_cancelling_from_the_panel_requires_a_reason(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListDeliveryIncidents::class)
            ->callTableAction('cancel', $this->incident, ['note' => ''])
            ->assertHasTableActionErrors(['note']);

        $this->assertSame(IncidentStatus::REPORTED, $this->incident->fresh()->status);
    }

    public function test_the_resource_offers_no_create_or_edit_route(): void
    {
        $pages = DeliveryIncidentResource::getPages();

        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    public function test_a_role_without_the_module_cannot_open_the_list(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        $this->actingAs($finance)->get(DeliveryIncidentResource::getUrl())->assertForbidden();

        PermissionMatrix::set(Role::FINANCE, PanelModule::DELIVERY_INCIDENTS, ['view', 'edit']);
        PermissionMatrix::forget();

        $this->actingAs($finance)->get(DeliveryIncidentResource::getUrl())->assertSuccessful();
    }

    /**
     * A read-only grant renders the list and refuses the decisions.
     *
     * Both halves are asserted, because a screen whose buttons appear and then do nothing is
     * worse than a screen without them.
     */
    public function test_a_view_only_grant_can_read_but_not_decide(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        PermissionMatrix::set(Role::FINANCE, PanelModule::DELIVERY_INCIDENTS, ['view']);
        PermissionMatrix::forget();

        $this->actingAs($finance)->get(DeliveryIncidentResource::getUrl())->assertSuccessful();

        Livewire::actingAs($finance)
            ->test(ListDeliveryIncidents::class)
            ->assertTableActionHidden('partial', $this->incident)
            ->assertTableActionHidden('cancel', $this->incident);

        $this->assertSame(IncidentStatus::REPORTED, $this->incident->fresh()->status);
    }
}
