<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HTTP surface over AttendanceService. The sequence itself is proven in AttendanceTest;
 * these cover the wiring, and especially `GET /absen/status`, which is what the app reads to
 * decide whether the absen button is pressable at all.
 */
class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $barista;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->barista = User::query()->where('phone_e164', '081100000003')->firstOrFail();
        $this->staff = User::query()->where('phone_e164', '081100000005')->firstOrFail();
    }

    public function test_a_barista_can_clock_in(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/absen')
            ->assertCreated()
            ->assertJsonPath('data.role', Role::BARISTA->value)
            ->assertJsonStructure(['data' => ['id', 'operating_date', 'clocked_in_at']]);
    }

    /** A second tap returns 200 with the same row, not an error — see AttendanceService. */
    public function test_clocking_in_twice_is_a_replay_not_an_error(): void
    {
        $first = $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/absen')
            ->assertCreated();

        $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/absen')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.clocked_in_at', $first->json('data.clocked_in_at'));
    }

    public function test_staff_absen_is_refused_with_a_reason_until_the_barista_opens_it(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/absen')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Absen staff belum dibuka. Tunggu barista membuka absen setelah kopi siap.');
    }

    public function test_the_full_sequence_works_end_to_end(): void
    {
        $this->actingAs($this->barista, 'sanctum')->postJson('/api/v1/absen')->assertCreated();
        $this->actingAs($this->barista, 'sanctum')->postJson('/api/v1/absen/open')->assertSuccessful();
        $this->actingAs($this->staff, 'sanctum')->postJson('/api/v1/absen')->assertCreated();
    }

    public function test_a_barista_cannot_open_the_gate_before_their_own_absen(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->postJson('/api/v1/absen/open')
            ->assertStatus(422);
    }

    public function test_staff_cannot_open_the_gate(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/absen/open')
            ->assertForbidden();
    }

    /** The one call the app makes to render both buttons. */
    public function test_status_tells_a_fresh_barista_they_may_absen_but_not_yet_open(): void
    {
        $this->actingAs($this->barista, 'sanctum')
            ->getJson('/api/v1/absen/status')
            ->assertSuccessful()
            ->assertJsonPath('data.has_clocked_in', false)
            ->assertJsonPath('data.can_clock_in', true)
            ->assertJsonPath('data.can_open_staff_window', false)
            ->assertJsonPath('data.staff_window_open', false);
    }

    public function test_status_offers_the_open_button_once_the_barista_has_clocked_in(): void
    {
        app(AttendanceService::class)->clockIn($this->barista);

        $this->actingAs($this->barista, 'sanctum')
            ->getJson('/api/v1/absen/status')
            ->assertSuccessful()
            ->assertJsonPath('data.has_clocked_in', true)
            ->assertJsonPath('data.can_clock_in', false)
            ->assertJsonPath('data.can_open_staff_window', true)
            ->assertJsonPath('data.blocked_reason', 'Sudah absen hari ini.');
    }

    public function test_status_hides_the_open_button_once_the_gate_is_already_open(): void
    {
        $service = app(AttendanceService::class);
        $service->clockIn($this->barista);
        $service->openStaffWindow($this->barista);

        $this->actingAs($this->barista, 'sanctum')
            ->getJson('/api/v1/absen/status')
            ->assertSuccessful()
            ->assertJsonPath('data.staff_window_open', true)
            ->assertJsonPath('data.can_open_staff_window', false);
    }

    public function test_status_tells_staff_why_their_button_is_disabled(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/absen/status')
            ->assertSuccessful()
            ->assertJsonPath('data.can_clock_in', false)
            ->assertJsonPath('data.blocked_reason', 'Menunggu barista membuka absen.');
    }

    public function test_status_enables_staff_once_the_gate_is_open(): void
    {
        $service = app(AttendanceService::class);
        $service->clockIn($this->barista);
        $service->openStaffWindow($this->barista);

        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/absen/status')
            ->assertSuccessful()
            ->assertJsonPath('data.can_clock_in', true)
            ->assertJsonPath('data.blocked_reason', null);
    }

    public function test_a_role_with_no_shift_is_told_so(): void
    {
        $rider = User::factory()->role(Role::RIDER)->create();

        $this->actingAs($rider, 'sanctum')
            ->getJson('/api/v1/absen/status')
            ->assertSuccessful()
            ->assertJsonPath('data.can_clock_in', false)
            ->assertJsonPath('data.blocked_reason', 'Role ini tidak memiliki absen.');

        $this->actingAs($rider, 'sanctum')
            ->postJson('/api/v1/absen')
            ->assertStatus(422);
    }

    public function test_the_roll_call_lists_who_is_on_shift(): void
    {
        $service = app(AttendanceService::class);
        $service->clockIn($this->barista);
        $service->openStaffWindow($this->barista);
        $service->clockIn($this->staff);

        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/absen')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['user_id', 'user_name', 'role', 'clocked_in_at']]]);
    }

    public function test_absen_requires_authentication(): void
    {
        $this->postJson('/api/v1/absen')->assertUnauthorized();
        $this->getJson('/api/v1/absen/status')->assertUnauthorized();
    }
}
