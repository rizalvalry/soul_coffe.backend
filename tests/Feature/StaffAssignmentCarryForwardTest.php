<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CentralKitchen;
use App\Models\Cart;
use App\Models\Location;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The 00:00 writer behind "Penugasan Staff" no longer needing daily re-entry.
 *
 * Every test starts from yesterday's roster and asserts what today looks like — the whole point
 * of this service is that a day with no admin action still ends up staffed the same as the day
 * before, except where a human decision (a manual row for today) says otherwise.
 */
class StaffAssignmentCarryForwardTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchen = CentralKitchen::query()->create([
            'name' => 'Dapur Pusat A',
            'address' => 'Jl. Contoh 1',
            'open_at' => '05:00',
            'close_at' => '20:00',
        ]);

        $this->location = Location::query()->create([
            'name' => 'Titik A',
            'lat' => -6.2,
            'lng' => 106.8,
        ]);
    }

    private function cart(string $code, string $status = 'active'): Cart
    {
        return Cart::query()->create(['code' => $code, 'status' => $status, 'kitchen_id' => $this->kitchen->id]);
    }

    private function assign(User $staff, Cart $cart, Carbon $date, ?User $assignedBy = null): StaffAssignment
    {
        return StaffAssignment::query()->create([
            'user_id' => $staff->id,
            'cart_id' => $cart->id,
            'location_id' => $this->location->id,
            'operating_date' => $date->toDateString(),
            'assigned_by' => ($assignedBy ?? $staff)->id,
            'kitchen_id' => $cart->kitchen_id,
        ]);
    }

    public function test_yesterdays_roster_is_cloned_onto_today(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $staff = User::factory()->role(Role::STAFF)->create();
        $cart = $this->cart('0001');

        $yesterday = Carbon::yesterday();
        $this->assign($staff, $cart, $yesterday, $admin);

        $this->artisan('soul:carry-forward-staff-assignments')->assertSuccessful();

        $today = StaffAssignment::query()
            ->where('user_id', $staff->id)
            ->whereDate('operating_date', Carbon::today())
            ->first();

        $this->assertNotNull($today);
        $this->assertSame($cart->id, $today->cart_id);
        $this->assertSame($this->location->id, $today->location_id);
        $this->assertSame($this->kitchen->id, $today->kitchen_id);
        // The roster decision being repeated is still the admin's, not a synthetic system actor.
        $this->assertSame($admin->id, $today->assigned_by);
    }

    public function test_a_manual_row_for_today_is_never_overwritten(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $staff = User::factory()->role(Role::STAFF)->create();
        $cartA = $this->cart('0001');
        $cartB = $this->cart('0002');

        $this->assign($staff, $cartA, Carbon::yesterday(), $admin);
        // The admin already moved this staff member to a different cart for today, by hand.
        $this->assign($staff, $cartB, Carbon::today(), $admin);

        $this->artisan('soul:carry-forward-staff-assignments')->assertSuccessful();

        $this->assertSame(1, StaffAssignment::query()
            ->where('user_id', $staff->id)
            ->whereDate('operating_date', Carbon::today())
            ->count());
        $this->assertSame($cartB->id, StaffAssignment::query()
            ->where('user_id', $staff->id)
            ->whereDate('operating_date', Carbon::today())
            ->value('cart_id'));
    }

    public function test_a_cart_already_claimed_today_is_not_double_assigned(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $staffA = User::factory()->role(Role::STAFF)->create();
        $staffB = User::factory()->role(Role::STAFF)->create();
        $cart = $this->cart('0001');

        $this->assign($staffA, $cart, Carbon::yesterday(), $admin);
        // Someone else was put on this same cart for today before the carry-forward ran.
        $this->assign($staffB, $cart, Carbon::today(), $admin);

        $this->artisan('soul:carry-forward-staff-assignments')->assertSuccessful();

        $this->assertSame(0, StaffAssignment::query()
            ->where('user_id', $staffA->id)
            ->whereDate('operating_date', Carbon::today())
            ->count());
    }

    public function test_a_deactivated_staff_member_is_not_re_rostered(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $staff = User::factory()->role(Role::STAFF)->create(['is_active' => false]);
        $cart = $this->cart('0001');

        $this->assign($staff, $cart, Carbon::yesterday(), $admin);

        $this->artisan('soul:carry-forward-staff-assignments')->assertSuccessful();

        $this->assertSame(0, StaffAssignment::query()
            ->whereDate('operating_date', Carbon::today())
            ->count());
    }

    public function test_a_cart_no_longer_active_is_not_carried_forward(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $staff = User::factory()->role(Role::STAFF)->create();
        $cart = $this->cart('0001', 'maintenance');

        $this->assign($staff, $cart, Carbon::yesterday(), $admin);

        $this->artisan('soul:carry-forward-staff-assignments')->assertSuccessful();

        $this->assertSame(0, StaffAssignment::query()
            ->whereDate('operating_date', Carbon::today())
            ->count());
    }

    /** Cron on this host can fire twice in the same minute (see routes/console.php). */
    public function test_running_twice_creates_nothing_the_second_time(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $staff = User::factory()->role(Role::STAFF)->create();
        $cart = $this->cart('0001');

        $this->assign($staff, $cart, Carbon::yesterday(), $admin);

        $this->artisan('soul:carry-forward-staff-assignments')->assertSuccessful();
        $this->artisan('soul:carry-forward-staff-assignments')->assertSuccessful();

        $this->assertSame(1, StaffAssignment::query()
            ->whereDate('operating_date', Carbon::today())
            ->count());
    }

    public function test_a_custom_date_option_targets_that_date_instead_of_today(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $staff = User::factory()->role(Role::STAFF)->create();
        $cart = $this->cart('0001');

        $base = Carbon::parse('2026-03-10');
        $this->assign($staff, $cart, $base);

        $this->artisan('soul:carry-forward-staff-assignments', ['--date' => '2026-03-11'])->assertSuccessful();

        $this->assertSame(1, StaffAssignment::query()
            ->whereDate('operating_date', '2026-03-11')
            ->count());
    }
}
