<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Resources\PinResetRequests\Pages\ListPinResetRequests;
use App\Filament\Resources\PinResetRequests\PinResetRequestResource;
use App\Models\PinResetRequest;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Administrator's side of "lupa PIN".
 *
 * The page is exercised through Livewire rather than asserted structurally: the resolve action
 * carries the three side effects that make the flow work (new password, cleared PIN, revoked
 * sessions), and a test that only checked the class compiled would not notice if the button
 * stopped being wired to them.
 */
class PinResetRequestPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private PinResetRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->staff = User::factory()->role(Role::STAFF)->create([
            'password' => Hash::make('lama123456'),
            'login_pin_hash' => Hash::make('482915'),
        ]);

        $this->request = PinResetRequest::query()->create([
            'user_id' => $this->staff->id,
            'phone_e164' => $this->staff->phone_e164,
            'email' => 'staff@example.com',
            'password_verified' => true,
            'status' => PinResetRequest::STATUS_PENDING,
            'attempts' => 1,
        ]);
    }

    public function test_the_list_page_renders_for_an_administrator(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListPinResetRequests::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->request]);
    }

    /** The badge is the in-panel notification; it must count only what is still waiting. */
    public function test_the_navigation_badge_counts_pending_requests(): void
    {
        $this->actingAs($this->admin);

        $this->assertSame('1', PinResetRequestResource::getNavigationBadge());

        $this->request->forceFill(['status' => PinResetRequest::STATUS_RESOLVED])->save();

        $this->assertNull(PinResetRequestResource::getNavigationBadge());
    }

    public function test_a_non_administrator_gets_no_badge_and_no_access(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $this->actingAs($finance);

        $this->assertNull(PinResetRequestResource::getNavigationBadge());
        $this->assertFalse(PinResetRequestResource::canViewAny());
    }

    public function test_resolving_from_the_panel_resets_the_account(): void
    {
        $this->actingAs($this->admin);
        $this->staff->createToken('phone-in-the-field');

        Livewire::test(ListPinResetRequests::class)
            ->callAction(TestAction::make('resolve')->table($this->request), [
                'password' => 'SandiBaru123',
                'password_confirmation' => 'SandiBaru123',
                'note' => 'diverifikasi via telepon',
            ])
            ->assertHasNoActionErrors();

        $fresh = $this->staff->fresh();
        $this->assertTrue(Hash::check('SandiBaru123', $fresh->password));
        $this->assertNull($fresh->login_pin_hash);
        $this->assertSame(0, $fresh->tokens()->count());

        $this->assertSame(PinResetRequest::STATUS_RESOLVED, $this->request->fresh()->status);
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListPinResetRequests::class)
            ->callAction(TestAction::make('resolve')->table($this->request), [
                'password' => 'SandiBaru123',
                'password_confirmation' => 'SalahKetik99',
            ])
            ->assertHasActionErrors(['password']);

        $this->assertTrue(Hash::check('lama123456', $this->staff->fresh()->password));
        $this->assertSame(PinResetRequest::STATUS_PENDING, $this->request->fresh()->status);
    }

    public function test_rejecting_leaves_credentials_alone(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListPinResetRequests::class)
            ->callAction(TestAction::make('reject')->table($this->request), ['note' => 'tidak dapat memastikan identitas'])
            ->assertHasNoActionErrors();

        $fresh = $this->staff->fresh();
        $this->assertTrue(Hash::check('lama123456', $fresh->password));
        $this->assertNotNull($fresh->login_pin_hash);

        $this->assertSame(PinResetRequest::STATUS_REJECTED, $this->request->fresh()->status);
    }
}
