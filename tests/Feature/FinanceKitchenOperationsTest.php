<?php

namespace Tests\Feature;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Media;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RefillRequest;
use App\Models\RiderLocationSetup;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FinanceKitchenOperationsTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Product $product;

    private User $staff;

    private User $barista;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        config(['outbox.publish_mode' => 'queue']);
        $this->seed();
        $this->kitchen = CentralKitchen::query()->firstOrFail();
        $this->cart = Cart::query()->where('code', '0018')->firstOrFail();
        $this->product = Product::query()->where('name', 'Soul Coffee')->firstOrFail();
        ProductPriceVersion::query()->create([
            'product_id' => $this->product->id,
            'cost_price_minor' => 8000,
            'sell_price_minor' => 20000,
            'effective_from' => now()->subDay(),
        ]);
        $this->staff = User::query()->where('role', Role::STAFF)->firstOrFail();
        $this->barista = User::query()->where('role', Role::BARISTA)->firstOrFail();
        $this->finance = User::query()->where('role', Role::FINANCE)->firstOrFail();
        RiderLocationSetup::query()->create([
            'rider_id' => $this->staff->id,
            'location_id' => Location::query()->firstOrFail()->id,
            'basis' => 'management',
            'changed_by' => $this->finance->id,
        ]);
    }

    private function postAs(User $actor, string $path, array $payload): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/'.$path, $payload);
    }

    private function kitchenPayload(User $actor, array $payload = []): array
    {
        return $actor->role === Role::BARISTA ? $payload : ['kitchen_id' => $this->kitchen->id] + $payload;
    }

    private function evidence(): Media
    {
        return Media::query()->create([
            'kind' => 'evidence', 'path' => 'evidence/'.Str::uuid().'.jpg', 'mime' => 'image/jpeg',
            'bytes' => 12345, 'sha256' => hash('sha256', (string) Str::uuid()),
            'exif_taken_at' => now(), 'uploaded_by' => $this->staff->id,
        ]);
    }

    /** Runs every kitchen write through the same HTTP endpoints and compares side effects. */
    private function runDay(User $actor): array
    {
        $lastMovementId = StockLedger::query()->max('id') ?? 0;
        $lastJournalId = JournalEntry::query()->max('id') ?? 0;
        if ($actor->role === Role::BARISTA) {
            $this->postAs($actor, 'absen', [])->assertCreated();
        } else {
            $this->actingAs($actor, 'sanctum')->getJson('/api/v1/absen/status')
                ->assertOk()->assertJsonPath('data.can_clock_in', false)
                ->assertJsonPath('data.can_open_staff_window', true);
        }
        $this->postAs($actor, 'absen/open', $this->kitchenPayload($actor))->assertOk();
        $this->postAs($this->staff, 'absen', [])->assertCreated();

        $this->postAs($actor, 'showcase/brew', $this->kitchenPayload($actor, [
            'lines' => [['product_id' => $this->product->id, 'qty' => 8]],
        ]))->assertOk();
        $this->postAs($actor, 'showcase/hand-to-cart', $this->kitchenPayload($actor, [
            'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
        ]))->assertCreated();
        $this->postAs($actor, 'allocations', $this->kitchenPayload($actor, [
            'operating_date' => today()->toDateString(), 'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id, 'location_id' => Location::query()->firstOrFail()->id,
            'lines' => [['product_id' => $this->product->id, 'qty_issued' => 2]],
        ]))->assertCreated();

        $submitted = $this->postAs($this->staff, 'refills', [
            'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
            'evidence_media_id' => $this->evidence()->id,
            'lines' => [['product_id' => $this->product->id, 'qty_requested' => 1]],
        ])->assertCreated();
        $refill = RefillRequest::query()->findOrFail($submitted->json('data.id'));
        $line = $refill->lines()->firstOrFail();
        $this->postAs($this->finance, 'refills/'.$refill->id.'/approve', [
            'lines' => [['line_id' => $line->id, 'qty_approved' => 1]],
        ])->assertOk();
        $this->postAs($actor, 'refills/'.$refill->id.'/start-preparing',
            $this->kitchenPayload($actor))->assertOk();
        $this->postAs($actor, 'refills/'.$refill->id.'/ready', $this->kitchenPayload($actor, [
            'lines' => [['line_id' => $line->id, 'qty_prepared' => 1]],
        ]))->assertOk();

        $this->postAs($actor, 'showcase/close-out', $this->kitchenPayload($actor, [
            'cart_id' => $this->cart->id,
            'returned' => [['product_id' => $this->product->id, 'qty' => 1]],
            'rejected' => [['product_id' => $this->product->id, 'qty' => 1]],
        ]))->assertOk();

        $audit = AuditLog::query()->where('actor_id', $actor->id)
            ->whereIn('action', ['staff_attendance_window.opened', 'central_stock.brew',
                'central_stock.hand_to_cart', 'daily_allocation.create', 'central_stock.close_out',
                'refill.start_preparing', 'refill.ready'])
            ->whereDate('created_at', today())->get();
        $this->assertCount(7, $audit);
        foreach ($audit as $row) {
            $this->assertSame($actor->role->value, $row->actor_role);
            $this->assertSame($this->kitchen->id, (int) $row->after_json['kitchen_id']);
        }
        $this->assertSame($actor->role->value,
            $refill->fresh()->statusHistory()->where('to_status', 'READY_TO_PICK')->firstOrFail()->actor_role);

        $movements = StockLedger::query()->where('id', '>', $lastMovementId)
            ->where('actor_id', $actor->id)->whereDate('created_at', today())
            ->orderBy('id')->get()->map(fn (StockLedger $row): array => [
                $row->movement_type->value, (int) $row->qty_delta,
            ])->all();
        $journals = JournalEntry::query()->with('lines')->where('id', '>', $lastJournalId)
            ->where('posted_by', $actor->id)
            ->whereDate('entry_date', today())->orderBy('id')->get()
            ->map(fn (JournalEntry $entry): array => [$entry->source_type,
                preg_replace('/DAY_\d{8}/', 'DAY_DATE', $entry->purpose),
                (int) $entry->lines->sum('debit_minor'), (int) $entry->lines->sum('credit_minor')])->all();
        $events = OutboxEvent::query()->whereDate('created_at', today())
            ->whereIn('name', ['StaffAttendanceWindowOpened', 'ShowcaseBrewed', 'CartStockHandedOver',
                'DailyAllocationIssued', 'RefillPreparingStarted', 'RefillReadyToPick', 'CartClosedOut'])
            ->orderBy('id')->pluck('name')->all();

        return compact('movements', 'journals', 'events');
    }

    public function test_finance_and_produksi_make_matching_stock_journal_and_notification_posts(): void
    {
        $start = Carbon::today()->addHours(8);
        try {
            Carbon::setTestNow($start);
            $barista = $this->runDay($this->barista);
            Carbon::setTestNow($start->copy()->addDay());
            $finance = $this->runDay($this->finance);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertNotEmpty($finance['movements']);
        $this->assertNotEmpty($finance['journals']);
        $this->assertSame($barista, $finance);
    }

    public function test_finance_requires_an_active_selected_kitchen_and_produksi_uses_own_kitchen(): void
    {
        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/showcase/stock')
            ->assertUnprocessable()->assertJsonValidationErrors('kitchen_id');
        $inactive = CentralKitchen::query()->create([
            'name' => 'Dapur Libur', 'address' => 'Jl. Uji', 'is_active' => false,
            'open_at' => '06:00:00', 'close_at' => '18:00:00',
        ]);
        $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/showcase/stock?kitchen_id='.$inactive->id)
            ->assertUnprocessable()->assertJsonValidationErrors('kitchen_id');
        $this->actingAs($this->barista, 'sanctum')->getJson('/api/v1/showcase/stock')->assertOk();
        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/showcase/kitchens')
            ->assertOk()->assertJsonMissing(['id' => $inactive->id]);
    }

    public function test_unauthorized_roles_get_403_on_every_kitchen_endpoint(): void
    {
        $this->runDay($this->barista);
        $refill = RefillRequest::query()->firstOrFail();
        foreach ([Role::STAFF, Role::RIDER, Role::CONTENT_CREATOR] as $role) {
            $user = User::factory()->role($role)->create();
            foreach (['showcase/kitchens', 'showcase/stock', 'showcase/staff', 'kitchen/stock',
                'allocations/today', 'showcase/close-out/draft/'.$this->cart->id,
                'showcase/allowance/'.$this->cart->id] as $path) {
                $this->actingAs($user, 'sanctum')->getJson('/api/v1/'.$path)->assertForbidden();
            }
            foreach (['absen/open', 'showcase/brew', 'showcase/hand-to-cart',
                'showcase/close-out', 'allocations'] as $path) {
                $this->postAs($user, $path, [])->assertForbidden();
            }
            $this->postAs($user, 'refills/'.$refill->id.'/start-preparing', [])->assertForbidden();
            $this->postAs($user, 'refills/'.$refill->id.'/ready', [])->assertForbidden();
        }
    }

    public function test_unbound_cart_cannot_move_or_expose_stock_across_kitchens_after_assignment(): void
    {
        $this->cart->update(['kitchen_id' => null]);
        $other = CentralKitchen::query()->create([
            'name' => 'Dapur Dua', 'address' => 'Jl. Dua', 'is_active' => true,
            'open_at' => '06:00:00', 'close_at' => '18:00:00',
        ]);
        $this->postAs($this->finance, 'absen/open', ['kitchen_id' => $this->kitchen->id])->assertOk();
        $this->postAs($this->staff, 'absen', [])->assertCreated();
        $this->postAs($this->finance, 'showcase/brew', ['kitchen_id' => $this->kitchen->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 8]]])->assertOk();
        $this->postAs($this->finance, 'showcase/hand-to-cart', ['kitchen_id' => $this->kitchen->id,
            'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 5]]])->assertCreated();

        $this->actingAs($this->finance, 'sanctum')
            ->getJson('/api/v1/carts?scope=kitchen&kitchen_id='.$other->id)
            ->assertOk()->assertJsonMissing(['id' => $this->cart->id]);
        foreach (['showcase/close-out/draft/', 'showcase/allowance/'] as $path) {
            $this->actingAs($this->finance, 'sanctum')
                ->getJson('/api/v1/'.$path.$this->cart->id.'?kitchen_id='.$other->id)
                ->assertUnprocessable()->assertJsonValidationErrors('kitchen_id');
        }
        $this->postAs($this->finance, 'showcase/hand-to-cart', ['kitchen_id' => $other->id,
            'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('kitchen_id');
        $this->postAs($this->finance, 'allocations', ['kitchen_id' => $other->id,
            'operating_date' => today()->toDateString(), 'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id, 'location_id' => Location::query()->firstOrFail()->id,
            'lines' => [['product_id' => $this->product->id, 'qty_issued' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('kitchen_id');
        $this->postAs($this->finance, 'showcase/close-out', ['kitchen_id' => $other->id,
            'cart_id' => $this->cart->id,
            'returned' => [['product_id' => $this->product->id, 'qty' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('kitchen_id');
        $this->assertSame(0, (int) StockLedger::query()->where('location_type', StockLedgerService::KITCHEN)
            ->where('location_id', $other->id)->sum('qty_delta'));

        try {
            Carbon::setTestNow(Carbon::now()->addDay());
            $this->actingAs($this->finance, 'sanctum')
                ->getJson('/api/v1/carts?scope=kitchen&kitchen_id='.$other->id)
                ->assertOk()->assertJsonMissing(['id' => $this->cart->id]);
            $this->actingAs($this->finance, 'sanctum')
                ->getJson('/api/v1/showcase/close-out/draft/'.$this->cart->id.'?kitchen_id='.$other->id)
                ->assertUnprocessable()->assertJsonValidationErrors('kitchen_id');
            $this->postAs($this->finance, 'showcase/close-out', ['kitchen_id' => $other->id,
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 1]]])
                ->assertUnprocessable()->assertJsonValidationErrors('kitchen_id');
            $this->postAs($this->finance, 'absen/open', ['kitchen_id' => $other->id])->assertOk();
            $this->postAs($this->staff, 'absen', [])->assertCreated();
            $this->postAs($this->finance, 'showcase/brew', ['kitchen_id' => $other->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]]])->assertOk();
            $this->postAs($this->finance, 'showcase/hand-to-cart', ['kitchen_id' => $other->id,
                'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]]])
                ->assertUnprocessable()->assertJsonValidationErrors('kitchen_id');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_matrix_grant_and_revocation_control_refill_reads_and_kitchen_channels(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $holder = User::factory()->role(Role::CONTENT_CREATOR)->create();
        PermissionMatrix::setForUser($super, $holder, PanelModule::KITCHEN_OPERATIONS, ['view', 'edit']);
        $this->runDay($this->barista);
        $refill = RefillRequest::query()->firstOrFail();
        $query = '?kitchen_id='.$this->kitchen->id;
        $this->actingAs($holder, 'sanctum')->getJson('/api/v1/refills'.$query)->assertOk();
        $this->actingAs($holder, 'sanctum')->getJson('/api/v1/refills/'.$refill->id.$query)->assertOk();
        PermissionMatrix::setForUser($super, $this->barista, PanelModule::KITCHEN_OPERATIONS, []);
        $this->actingAs($this->barista, 'sanctum')->getJson('/api/v1/refills')->assertForbidden();
        $this->actingAs($this->barista, 'sanctum')->getJson('/api/v1/refills/'.$refill->id)->assertForbidden();
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
        $this->actingAs($this->barista, 'sanctum')->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678', 'channel_name' => 'private-kitchen.'.$this->kitchen->id,
        ])->assertForbidden();
    }
}
