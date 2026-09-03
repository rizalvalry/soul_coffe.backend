<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\DailyCartAllowance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 00:00 writer behind the pre-filled money field in the barista's Add Stock form.
 *
 * The re-run tests are the point of this file: on this hosting the scheduler is a cron entry
 * that can miss a minute or fire twice (PRODUCTION-ACCESS.md), so "runs again" has to mean
 * "nothing happens", not "the cart gets paid twice".
 */
class DailyAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_it_writes_one_allowance_per_active_cart(): void
    {
        DailyCartAllowance::query()->delete();
        $activeCarts = Cart::query()->where('status', 'active')->count();

        $this->artisan('soul:seed-daily-allowances')->assertSuccessful();

        $this->assertSame(
            $activeCarts,
            DailyCartAllowance::query()->whereDate('operating_date', now()->toDateString())->count(),
        );
        $this->assertGreaterThan(0, $activeCarts, 'No active carts — the assertion above would pass vacuously.');
    }

    public function test_it_uses_the_configured_amount(): void
    {
        DailyCartAllowance::query()->delete();
        config(['soul.daily_cart_allowance' => 65000]);

        $this->artisan('soul:seed-daily-allowances')->assertSuccessful();

        $this->assertSame(65000, DailyCartAllowance::query()->first()->amount_minor);
    }

    public function test_running_it_again_pays_nobody_twice(): void
    {
        DailyCartAllowance::query()->delete();

        $this->artisan('soul:seed-daily-allowances')->assertSuccessful();
        $afterFirst = DailyCartAllowance::query()->count();

        $this->artisan('soul:seed-daily-allowances')->assertSuccessful();

        $this->assertSame($afterFirst, DailyCartAllowance::query()->count());
    }

    /** A barista's edit must survive a later re-run of the scheduler that day. */
    public function test_a_rerun_does_not_overwrite_an_edited_amount(): void
    {
        DailyCartAllowance::query()->delete();
        $this->artisan('soul:seed-daily-allowances')->assertSuccessful();

        $allowance = DailyCartAllowance::query()->firstOrFail();
        $allowance->update(['amount_minor' => 100000, 'is_edited' => true]);

        $this->artisan('soul:seed-daily-allowances')->assertSuccessful();

        $this->assertSame(100000, $allowance->fresh()->amount_minor);
        $this->assertTrue($allowance->fresh()->is_edited);
    }

    public function test_a_cart_in_maintenance_gets_no_allowance(): void
    {
        DailyCartAllowance::query()->delete();
        $cart = Cart::query()->firstOrFail();
        $cart->update(['status' => 'maintenance']);

        $this->artisan('soul:seed-daily-allowances')->assertSuccessful();

        $this->assertSame(
            0,
            DailyCartAllowance::query()->where('cart_id', $cart->id)->count(),
            'A cart nobody is standing at should not be handed money.',
        );
    }

    public function test_it_can_be_run_for_a_specific_date(): void
    {
        DailyCartAllowance::query()->delete();
        $date = now()->addDay()->toDateString();

        $this->artisan("soul:seed-daily-allowances --date={$date}")->assertSuccessful();

        $this->assertGreaterThan(
            0,
            DailyCartAllowance::query()->whereDate('operating_date', $date)->count(),
        );
        $this->assertSame(
            0,
            DailyCartAllowance::query()->whereDate('operating_date', now()->toDateString())->count(),
        );
    }
}
