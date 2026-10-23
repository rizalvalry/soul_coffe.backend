<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Cart;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\Production;
use App\Models\RawMaterial;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\AttendanceService;
use App\Services\RecipeService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The HTTP surface over CentralStockService. The rules themselves are proven in
 * CentralStockTest — what these cover is the wiring: role gates, validation, idempotency, and
 * that the response carries enough back that the client needs no follow-up call.
 */
class ShowcaseStockApiTest extends TestCase
{
    use RefreshDatabase;

    private User $barista;

    private User $staff;

    private User $runner;

    private User $finance;

    private Cart $cart;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->barista = User::query()->where('phone_e164', '081100000003')->firstOrFail();
        $this->staff = User::query()->where('phone_e164', '081100000005')->firstOrFail();
        $this->runner = User::query()->where('role', Role::RIDER)->firstOrFail();
        $this->finance = User::query()->where('role', Role::FINANCE)->firstOrFail();
        $this->cart = Cart::query()->where('code', '0018')->firstOrFail();
        $this->product = Product::query()->where('name', 'Soul Coffee')->firstOrFail();
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista);
        $attendance->openStaffWindow($this->barista);
        $attendance->clockIn($this->staff);
        StaffAssignment::query()->create([
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => Location::query()->firstOrFail()->id,
            'operating_date' => today(),
            'assigned_by' => User::query()->where('role', Role::ADMINISTRATOR)->firstOrFail()->id,
            'kitchen_id' => $this->cart->kitchen_id,
            'source' => 'hand_to_cart',
        ]);
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function cartStock(): int
    {
        return app(StockLedgerService::class)
            ->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id);
    }

    public function test_a_barista_sees_their_showcase_stock(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->getJson('/api/v1/showcase/stock')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => [['product_id', 'product_name', 'qty']]]);
    }

    public function test_showcase_stock_write_responses_include_the_absolute_cms_product_image_url(): void
    {
        $this->product->update(['image_path' => 'products/t065-stock.jpg']);
        $expected = url('/storage/products/t065-stock.jpg');

        $stock = $this->actingAs($this->barista, 'sanctum')->getJson('/api/v1/showcase/stock')->assertOk();
        $this->assertSame($expected, collect($stock->json('data'))->firstWhere('product_id', $this->product->id)['image_url']);

        $brew = $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/brew', [
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertOk();
        $this->assertSame($expected, collect($brew->json('data'))->firstWhere('product_id', $this->product->id)['image_url']);

        $handover = $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertCreated();
        $this->assertSame($expected, collect($handover->json('data.cart_stock'))->firstWhere('product_id', $this->product->id)['image_url']);
        $this->assertSame($expected, collect($handover->json('data.showcase_stock'))->firstWhere('product_id', $this->product->id)['image_url']);

        $closeOut = $this->actingAs($this->runner, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/close-out', [
                'kitchen_id' => $this->cart->kitchen_id,
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertOk();
        $this->assertSame($expected, collect($closeOut->json('data.showcase_stock'))->firstWhere('product_id', $this->product->id)['image_url']);
    }

    public function test_brewing_raises_the_showcase_and_returns_the_new_totals(): void
    {
        $response = $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/brew', [
                'lines' => [['product_id' => $this->product->id, 'qty' => 12]],
            ])
            ->assertSuccessful();

        // The response is the refreshed showcase, so the client needs no second request.
        $row = collect($response->json('data'))->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($row);
        $this->assertSame(212, $row['qty']); // seeded 200 + 12
    }

    public function test_brew_150_cups_posts_exact_stock_and_recipe_cost_to_the_ledger_and_journal(): void
    {
        $raw = RawMaterial::query()->create([
            'code' => 'T069-RAW', 'name' => 'Bahan T-069', 'unit' => 'g', 'is_active' => true, 'sort_order' => 1,
        ]);
        $admin = User::query()->where('role', Role::ADMINISTRATOR)->firstOrFail();
        $recipe = app(RecipeService::class)->create($this->product->id, [
            ['raw_material_id' => $raw->id, 'qty_per_unit' => 2],
        ], $admin);
        app(StockLedgerService::class)->post(
            StockLedgerService::RAW_MATERIAL_STORE, $this->cart->kitchen_id, null,
            MovementType::PURCHASE_IN, 300, $admin->id, $this->cart->kitchen_id,
            rawMaterialId: $raw->id, costMinor: 9000,
        );
        app(RecipeService::class)->refreshCostsForRawMaterials([$raw->id], $this->cart->kitchen_id);
        $this->assertSame(60, $recipe->fresh()->computed_cost_minor);

        $response = $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/brew', [
                'lines' => [['product_id' => $this->product->id, 'qty' => 150]],
            ])->assertOk();

        $this->assertSame(350, collect($response->json('data'))->firstWhere('product_id', $this->product->id)['qty']);
        $production = Production::query()->latest('id')->firstOrFail();
        $this->assertSame(150, $production->lines()->sole()->qty_brewed);
        $this->assertSame(60, $production->lines()->sole()->recipe_cost_minor);
        $this->assertDatabaseHas('stock_ledger', [
            'ref_type' => 'production', 'ref_id' => $production->id,
            'movement_type' => MovementType::PRODUCTION_IN->value,
            'product_id' => $this->product->id, 'qty_delta' => 150, 'cost_minor' => 9000,
        ]);
        $this->assertDatabaseHas('stock_ledger', [
            'ref_type' => 'production', 'ref_id' => $production->id,
            'movement_type' => MovementType::RECIPE_CONSUME_OUT->value,
            'raw_material_id' => $raw->id, 'qty_delta' => -300, 'cost_minor' => 9000,
        ]);
        $journal = JournalEntry::query()->where('source_type', 'production')
            ->where('source_id', $production->id)->where('purpose', 'TRANSFER')->sole();
        $finished = Account::query()->where('code', '1-1600')->sole();
        $this->assertDatabaseHas('journal_lines', [
            'entry_id' => $journal->id, 'account_id' => $finished->id,
            'debit_minor' => 9000, 'credit_minor' => 0,
        ]);
        $rawInventory = Account::query()->where('code', '1-1700')->sole();
        $this->assertDatabaseHas('journal_lines', [
            'entry_id' => $journal->id, 'account_id' => $rawInventory->id,
            'debit_minor' => 0, 'credit_minor' => 9000,
        ]);
    }

    public function test_brew_rejects_zero_ten_thousand_and_duplicate_lines_above_the_per_product_limit(): void
    {
        foreach ([
            [['product_id' => $this->product->id, 'qty' => 0]],
            [['product_id' => $this->product->id, 'qty' => 10000]],
            [['product_id' => $this->product->id, 'qty' => 6000], ['product_id' => $this->product->id, 'qty' => 4000]],
        ] as $lines) {
            $response = $this->actingAs($this->barista, 'sanctum')
                ->withHeader('Idempotency-Key', $this->key())
                ->postJson('/api/v1/showcase/brew', ['lines' => $lines])
                ->assertUnprocessable();
            $this->assertStringContainsString(
                $lines[0]['qty'] === 0 ? 'lebih dari 0' : 'maksimal 9.999',
                $response->json('message'),
            );
        }

        $this->assertDatabaseCount('productions', 0);
    }

    public function test_handover_150_cups_uses_real_showcase_stock_instead_of_the_refill_limit(): void
    {
        app(StockLedgerService::class)->post(
            StockLedgerService::KITCHEN, $this->cart->kitchen_id, $this->product->id,
            MovementType::ADJUSTMENT, -50, $this->barista->id, $this->cart->kitchen_id,
        );
        $this->assertSame(150, app(StockLedgerService::class)->stockFor(
            StockLedgerService::KITCHEN, $this->cart->kitchen_id, $this->product->id,
        ));

        $response = $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 151]],
            ])->assertUnprocessable();
        $this->assertStringContainsString('Stok showcase tidak cukup', $response->json('message'));
        $this->assertSame(0, $this->cartStock());

        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 150]],
            ])->assertCreated();
        $this->assertSame(150, $this->cartStock());
        $this->assertSame(0, app(StockLedgerService::class)->stockFor(
            StockLedgerService::KITCHEN, $this->cart->kitchen_id, $this->product->id,
        ));
    }

    public function test_handover_rejects_ten_thousand_cups_per_product_before_stock_check(): void
    {
        foreach ([
            [['product_id' => $this->product->id, 'qty' => 10000]],
            [['product_id' => $this->product->id, 'qty' => 6000], ['product_id' => $this->product->id, 'qty' => 4000]],
        ] as $lines) {
            $response = $this->actingAs($this->barista, 'sanctum')
                ->withHeader('Idempotency-Key', $this->key())
                ->postJson('/api/v1/showcase/hand-to-cart', [
                    'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
                    'lines' => $lines,
                ])->assertUnprocessable();
            $this->assertStringContainsString('maksimal 9.999', $response->json('message'));
        }
        $this->assertSame(0, $this->cartStock());
    }

    public function test_handing_cups_over_returns_the_assignment_allowance_and_both_stocks(): void
    {
        $response = $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 20]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.cart_code', $this->cart->code)
            ->assertJsonPath('data.staff_id', $this->staff->id)
            ->assertJsonStructure([
                'data' => [
                    'assignment_id',
                    'allowance' => ['amount_minor', 'is_edited'],
                    'cart_stock',
                    'showcase_stock',
                ],
            ]);

        $this->assertSame(50000, $response->json('data.allowance.amount_minor'));
        $this->assertSame(20, $this->cartStock());
    }

    public function test_an_overridden_allowance_is_accepted_and_flagged(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
                'allowance_amount' => 80000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.allowance.amount_minor', 80000)
            ->assertJsonPath('data.allowance.is_edited', true);
    }

    /** R12: a retried tap in a kitchen must not hand the same cups over twice. */
    public function test_replaying_the_same_idempotency_key_does_not_move_stock_twice(): void
    {
        $key = $this->key();
        $payload = [
            'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 7]],
        ];

        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/showcase/hand-to-cart', $payload)
            ->assertCreated();

        $afterFirst = $this->cartStock();

        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/showcase/hand-to-cart', $payload)
            ->assertSuccessful();

        $this->assertSame($afterFirst, $this->cartStock());
    }

    /** 422, matching what the Idempotency middleware already returns everywhere else in this API. */
    public function test_a_write_without_an_idempotency_key_is_refused(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/showcase/brew', [
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
            ])
            ->assertStatus(422);
    }

    public function test_staff_cannot_reach_the_showcase_endpoints(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/showcase/stock')
            ->assertForbidden();

        $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
            ])
            ->assertForbidden();
    }

    public function test_handing_cups_to_a_non_staff_account_is_a_field_error(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->barista->id, // a barista, not staff
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_business_rule_failures_come_back_as_422_with_the_reason(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 100]],
            ])
            ->assertCreated();

        // Cart 0018 is now taken for today; a different staff member is a real conflict.
        $otherStaff = User::factory()->role(Role::STAFF)->create([
            'kitchen_id' => $this->barista->kitchen_id,
        ]);

        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $otherStaff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 5]],
            ])
            ->assertStatus(422);
    }

    public function test_close_out_moves_returned_and_rejected_cups_off_the_cart(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 30]],
            ])
            ->assertCreated();

        $this->actingAs($this->runner, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/close-out', [
                'kitchen_id' => $this->cart->kitchen_id,
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 6]],
                'rejected' => [['product_id' => $this->product->id, 'qty' => 2]],
            ])
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['cart_stock', 'showcase_stock']]);

        $this->assertSame(22, $this->cartStock()); // 30 - 6 - 2
        $this->assertDatabaseHas('audit_log', [
            'action' => 'central_stock.close_out', 'actor_id' => $this->runner->id,
            'actor_role' => Role::RIDER->value,
        ]);
    }

    public function test_close_out_permission_is_separate_from_produksi_and_finance_keeps_backup_access(): void
    {
        $draft = '/api/v1/showcase/close-out/draft/'.$this->cart->id;
        $this->actingAs($this->barista, 'sanctum')->getJson($draft)->assertForbidden();
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/close-out', [])->assertForbidden();
        $this->actingAs($this->runner, 'sanctum')->getJson('/api/v1/showcase/kitchens')->assertOk();
        $this->actingAs($this->runner, 'sanctum')
            ->getJson('/api/v1/carts?scope=kitchen&kitchen_id='.$this->cart->kitchen_id)->assertOk();
        foreach ([$this->runner, $this->finance] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->getJson($draft.'?kitchen_id='.$this->cart->kitchen_id)->assertOk();
        }

        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/brew', [
                'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
            ])->assertOk();
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
            ])->assertCreated();
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/close-out', [
                'kitchen_id' => $this->cart->kitchen_id, 'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertOk();
    }

    public function test_close_out_cart_picker_only_lists_active_bikes_assigned_today(): void
    {
        $unassigned = Cart::query()->create([
            'code' => 'T071-UNASSIGNED', 'status' => 'active', 'kitchen_id' => $this->cart->kitchen_id,
        ]);
        $path = '/api/v1/carts?scope=close-out&kitchen_id='.$this->cart->kitchen_id;
        $this->actingAs($this->runner, 'sanctum')->getJson($path)
            ->assertOk()->assertJsonFragment(['id' => $this->cart->id])
            ->assertJsonMissing(['id' => $unassigned->id]);
        $this->actingAs($this->finance, 'sanctum')->getJson($path)->assertOk();
        $this->actingAs($this->barista, 'sanctum')->getJson($path)->assertForbidden();
        $this->actingAs($this->barista, 'sanctum')
            ->getJson('/api/v1/carts?scope=kitchen')->assertOk()
            ->assertJsonFragment(['id' => $unassigned->id]);
    }

    public function test_matrix_can_restore_produksi_close_out_without_a_role_check_in_controller(): void
    {
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id, 'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertCreated();
        $this->actingAs($this->barista, 'sanctum')
            ->getJson('/api/v1/showcase/close-out/draft/'.$this->cart->id)->assertForbidden();
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        PermissionMatrix::set(Role::BARISTA, PanelModule::CART_CLOSE_OUT, ['view', 'edit'], $super);
        $this->actingAs($this->barista, 'sanctum')
            ->getJson('/api/v1/showcase/close-out/draft/'.$this->cart->id)->assertOk();
        $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/close-out', [
                'cart_id' => $this->cart->id,
                'returned' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])->assertOk();
    }

    public function test_close_out_with_nothing_reported_is_a_validation_error(): void
    {
        $this->actingAs($this->runner, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/close-out', ['kitchen_id' => $this->cart->kitchen_id, 'cart_id' => $this->cart->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('returned');
    }

    public function test_the_allowance_endpoint_creates_and_returns_todays_amount(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->getJson("/api/v1/showcase/allowance/{$this->cart->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.amount_minor', 50000)
            ->assertJsonPath('data.is_edited', false);
    }

    /**
     * The staff picker must list everyone, including people with no assignment yet — filtering
     * by assignment would hide exactly the staff this form exists to place.
     */
    public function test_the_staff_picker_lists_all_active_staff_with_their_current_cart(): void
    {
        $unassigned = User::factory()->role(Role::STAFF)->create(['name' => 'Belum Ditugaskan']);

        $response = $this->actingAs($this->barista, 'sanctum')
            ->getJson('/api/v1/showcase/staff')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => [['id', 'name', 'assigned_cart_id', 'assigned_cart_code']]]);

        $rows = collect($response->json('data'));

        // The seeded staff is on cart 0018 today, so the picker says so up front rather than
        // letting the barista discover the R11 conflict after typing the cups.
        $this->assertSame($this->cart->code, $rows->firstWhere('id', $this->staff->id)['assigned_cart_code']);

        // And the unassigned one is present with nothing against their name.
        $this->assertNull($rows->firstWhere('id', $unassigned->id)['assigned_cart_code']);
    }

    public function test_staff_cannot_read_the_staff_picker(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/showcase/staff')
            ->assertForbidden();
    }

    /** The reason this endpoint exists: no cart is blocked by paperwork nobody did. */
    public function test_a_cart_with_no_assignment_today_can_still_be_given_stock(): void
    {
        $locationId = (int) StaffAssignment::query()->firstOrFail()->location_id;
        StaffAssignment::query()->delete();

        $this->actingAs($this->barista, 'sanctum')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/showcase/hand-to-cart', [
                'cart_id' => $this->cart->id,
                'staff_id' => $this->staff->id,
                'lines' => [['product_id' => $this->product->id, 'qty' => 9]],
                'location_id' => $locationId,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('staff_assignments', [
            'cart_id' => $this->cart->id,
            'user_id' => $this->staff->id,
        ]);
    }
}
