<?php

namespace Tests\Feature\Reporting;

use App\Enums\Role;
use App\Enums\RefillStatus;
use App\Models\Attendance;
use App\Models\CentralKitchen;
use App\Models\Cart;
use App\Models\DailyCartAllowance;
use App\Models\Media;
use App\Models\RefillRequest;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Reporting\DashboardMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every number the owner/administrator dashboard shows, verified against rows planted directly —
 * not through the full business flow, because these queries only ever read `status`,
 * `operating_date`, and the money columns, and asserting THAT reading is what is under test here.
 */
class DashboardMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardMetricsService $metrics;
    private CentralKitchen $kitchen;
    private Cart $cart;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metrics = app(DashboardMetricsService::class);

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Test', 'address' => 'Jl. Uji 1', 'open_at' => '05:00', 'close_at' => '20:00',
        ]);
        $this->cart = Cart::create(['code' => '0001', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $this->staff = User::factory()->role(Role::STAFF)->create();
    }

    private function settlement(Carbon $date, int $declared, int $variance = 0): Settlement
    {
        return Settlement::query()->create([
            'operating_date' => $date->toDateString(),
            'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id,
            'declared_total_minor' => $declared,
            'expected_total_minor' => $declared - $variance,
            'variance_minor' => $variance,
        ]);
    }

    private function refill(Carbon $date, RefillStatus $status): RefillRequest
    {
        $media = Media::create([
            'kind' => 'evidence',
            'path' => 'evidence/'.Str::uuid().'.jpg',
            'mime' => 'image/jpeg',
            'bytes' => 100,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'uploaded_by' => $this->staff->id,
        ]);

        return RefillRequest::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'REF-'.Str::random(10),
            'operating_date' => $date->toDateString(),
            'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id,
            'kitchen_id' => $this->kitchen->id,
            'status' => $status,
            'evidence_photo_id' => $media->id,
            'submitted_at' => $date,
        ]);
    }

    // ── todaySnapshot ────────────────────────────────────────────────────

    public function test_today_snapshot_sums_revenue_and_counts_refills_and_attendance(): void
    {
        $this->settlement(Carbon::today(), 150_000);
        $this->settlement(Carbon::yesterday(), 100_000);

        $this->refill(Carbon::today(), RefillStatus::SUBMITTED);
        $this->refill(Carbon::today(), RefillStatus::DELIVERED);
        $this->refill(Carbon::yesterday(), RefillStatus::CLOSED);

        Attendance::create([
            'operating_date' => Carbon::today(),
            'user_id' => $this->staff->id,
            'role' => Role::STAFF,
            'clocked_in_at' => now(),
        ]);

        DailyCartAllowance::create([
            'operating_date' => Carbon::today(),
            'cart_id' => $this->cart->id,
            'amount_minor' => 50_000,
        ]);

        $snapshot = $this->metrics->todaySnapshot();

        $this->assertSame(150_000, $snapshot['revenue_today_minor']);
        $this->assertSame(100_000, $snapshot['revenue_yesterday_minor']);
        $this->assertSame(2, $snapshot['refills_today']);
        $this->assertSame(['SUBMITTED' => 1, 'DELIVERED' => 1], $snapshot['refills_today_by_status']);
        $this->assertSame(1, $snapshot['staff_clocked_in_today']);
        $this->assertSame(1, $snapshot['staff_total_active']);
        $this->assertSame(50_000, $snapshot['allowance_disbursed_today_minor']);
    }

    public function test_today_snapshot_is_zero_when_nothing_happened_yet(): void
    {
        $snapshot = $this->metrics->todaySnapshot();

        $this->assertSame(0, $snapshot['revenue_today_minor']);
        $this->assertSame(0, $snapshot['refills_today']);
        $this->assertSame([], $snapshot['refills_today_by_status']);
    }

    // ── revenueTrend ─────────────────────────────────────────────────────

    public function test_revenue_trend_fills_every_day_including_days_with_no_settlement(): void
    {
        $this->settlement(Carbon::today(), 200_000);
        $this->settlement(Carbon::today()->subDays(2), 100_000);

        $trend = $this->metrics->revenueTrend(days: 5);

        $this->assertCount(5, $trend);
        $this->assertSame(Carbon::today()->subDays(4)->toDateString(), $trend->first()['date']);
        $this->assertSame(Carbon::today()->toDateString(), $trend->last()['date']);
        $this->assertSame(200_000, $trend->last()['revenue_minor']);
        $this->assertSame(0, $trend->firstWhere('date', Carbon::today()->subDays(1)->toDateString())['revenue_minor']);
        $this->assertSame(100_000, $trend->firstWhere('date', Carbon::today()->subDays(2)->toDateString())['revenue_minor']);
    }

    public function test_revenue_trend_sums_multiple_carts_on_the_same_day(): void
    {
        $otherCart = Cart::create(['code' => '0002', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);

        Settlement::query()->create([
            'operating_date' => Carbon::today()->toDateString(),
            'cart_id' => $otherCart->id,
            'staff_id' => $this->staff->id,
            'declared_total_minor' => 75_000,
        ]);
        $this->settlement(Carbon::today(), 25_000);

        $trend = $this->metrics->revenueTrend(days: 1);

        $this->assertSame(100_000, $trend->first()['revenue_minor']);
    }

    // ── refillVolumeTrend ────────────────────────────────────────────────

    public function test_refill_volume_trend_counts_per_day(): void
    {
        $this->refill(Carbon::today(), RefillStatus::SUBMITTED);
        $this->refill(Carbon::today(), RefillStatus::DELIVERED);
        $this->refill(Carbon::today()->subDay(), RefillStatus::CLOSED);

        $trend = $this->metrics->refillVolumeTrend(days: 3);

        $this->assertSame(2, $trend->last()['count']);
        $this->assertSame(1, $trend->firstWhere('date', Carbon::today()->subDay()->toDateString())['count']);
        $this->assertSame(0, $trend->first()['count']);
    }

    // ── refillStatusDistribution ─────────────────────────────────────────

    public function test_refill_status_distribution_counts_by_status_within_the_window(): void
    {
        $this->refill(Carbon::today(), RefillStatus::CLOSED);
        $this->refill(Carbon::today(), RefillStatus::CLOSED);
        $this->refill(Carbon::today(), RefillStatus::REJECTED);
        // Outside the 7-day window — must not be counted.
        $this->refill(Carbon::today()->subDays(10), RefillStatus::CLOSED);

        $distribution = $this->metrics->refillStatusDistribution(days: 7);

        $this->assertSame(['CLOSED' => 2, 'REJECTED' => 1], $distribution);
    }

    // ── attendanceRateTrend ──────────────────────────────────────────────

    public function test_attendance_rate_trend_divides_clocked_in_by_active_staff(): void
    {
        $second = User::factory()->role(Role::STAFF)->create();

        Attendance::create([
            'operating_date' => Carbon::today(), 'user_id' => $this->staff->id,
            'role' => Role::STAFF, 'clocked_in_at' => now(),
        ]);
        Attendance::create([
            'operating_date' => Carbon::today(), 'user_id' => $second->id,
            'role' => Role::STAFF, 'clocked_in_at' => now(),
        ]);

        $trend = $this->metrics->attendanceRateTrend(days: 2);

        $this->assertSame(100.0, $trend->last()['rate']);
        $this->assertSame(0.0, $trend->first()['rate']);
    }

    public function test_attendance_rate_trend_never_divides_by_zero_with_no_active_staff(): void
    {
        User::query()->where('role', Role::STAFF)->update(['is_active' => false]);

        $trend = $this->metrics->attendanceRateTrend(days: 1);

        $this->assertSame(0.0, $trend->first()['rate']);
    }
}
