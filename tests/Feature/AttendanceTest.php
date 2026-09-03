<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Absen, and the sequence it exists to enforce: barista clocks in, brews, opens the gate — only
 * then can staff clock in. Every test here is a step in that order or an attempt to skip one.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $barista;

    private User $staff;

    private AttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->barista = User::query()->where('phone_e164', '081100000003')->firstOrFail();
        $this->staff = User::query()->where('phone_e164', '081100000005')->firstOrFail();
        $this->service = app(AttendanceService::class);
    }

    public function test_a_barista_can_clock_in_without_anyone_opening_anything(): void
    {
        $attendance = $this->service->clockIn($this->barista);

        $this->assertSame((int) $this->barista->id, (int) $attendance->user_id);
        $this->assertSame(Role::BARISTA, $attendance->role);
        $this->assertNotNull($attendance->clocked_in_at);
    }

    /** A double-tap on a slow connection is the normal case, not misuse. */
    public function test_clocking_in_twice_returns_the_same_record_and_keeps_the_first_time(): void
    {
        $first = $this->service->clockIn($this->barista);
        $firstTime = $first->clocked_in_at;

        $second = $this->service->clockIn($this->barista);

        $this->assertSame($first->id, $second->id);
        $this->assertEquals($firstTime, $second->clocked_in_at);
        $this->assertSame(1, Attendance::query()->where('user_id', $this->barista->id)->count());
    }

    public function test_staff_cannot_clock_in_before_a_barista_opens_the_gate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Absen staff belum dibuka');

        $this->service->clockIn($this->staff);
    }

    public function test_a_barista_cannot_open_the_gate_before_clocking_in_themselves(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Absen dulu sebelum membuka absen staff');

        $this->service->openStaffWindow($this->barista);
    }

    public function test_staff_can_clock_in_once_the_barista_has_clocked_in_and_opened_the_gate(): void
    {
        $this->service->clockIn($this->barista);
        $this->service->openStaffWindow($this->barista);

        $attendance = $this->service->clockIn($this->staff);

        $this->assertSame((int) $this->staff->id, (int) $attendance->user_id);
        $this->assertSame(Role::STAFF, $attendance->role);
    }

    /** Product decision: one barista opening it opens it for everyone, not just their own cart. */
    public function test_the_gate_is_global_across_all_staff(): void
    {
        $otherStaff = User::factory()->role(Role::STAFF)->create();

        $this->service->clockIn($this->barista);
        $this->service->openStaffWindow($this->barista);

        $this->assertNotNull($this->service->clockIn($this->staff));
        $this->assertNotNull($this->service->clockIn($otherStaff));
    }

    public function test_opening_the_gate_twice_keeps_whoever_opened_it_first(): void
    {
        $otherBarista = User::factory()->role(Role::BARISTA)->create([
            'kitchen_id' => $this->barista->kitchen_id,
        ]);

        $this->service->clockIn($this->barista);
        $this->service->clockIn($otherBarista);

        $first = $this->service->openStaffWindow($this->barista);
        $second = $this->service->openStaffWindow($otherBarista);

        $this->assertSame($first->id, $second->id);
        $this->assertSame((int) $this->barista->id, (int) $second->opened_by);
    }

    public function test_only_a_barista_may_open_the_gate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Hanya barista');

        $this->service->openStaffWindow($this->staff);
    }

    /** Finance, Rider, Administrator and Content Creator have no shift to start. */
    public function test_roles_without_a_shift_have_no_absen(): void
    {
        foreach ([Role::FINANCE, Role::RIDER, Role::ADMINISTRATOR, Role::CONTENT_CREATOR] as $role) {
            $user = User::factory()->role($role)->create();

            try {
                $this->service->clockIn($user);
                $this->fail("{$role->value} should not have an absen.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('tidak memiliki absen', $e->getMessage());
            }
        }
    }

    /** Yesterday's open gate must not let staff clock in today without a barista. */
    public function test_the_gate_does_not_carry_over_to_the_next_day(): void
    {
        $yesterday = now()->subDay();

        $this->service->clockIn($this->barista, $yesterday);
        $this->service->openStaffWindow($this->barista, $yesterday);

        $this->assertTrue($this->service->isStaffWindowOpen($yesterday));
        $this->assertFalse($this->service->isStaffWindowOpen(now()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Absen staff belum dibuka');

        $this->service->clockIn($this->staff);
    }

    /** The role is stored, so a later role change can't rewrite what someone was that morning. */
    public function test_the_role_is_frozen_at_clock_in(): void
    {
        $attendance = $this->service->clockIn($this->barista);

        $this->barista->update(['role' => Role::FINANCE]);

        $this->assertSame(Role::BARISTA, $attendance->fresh()->role);
    }
}
