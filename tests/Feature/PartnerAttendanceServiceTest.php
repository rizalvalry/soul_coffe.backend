<?php

namespace Tests\Feature;

use App\Enums\PartnerAttendanceCode;
use App\Enums\Role;
use App\Models\Partner;
use App\Models\PartnerAttendanceEntry;
use App\Models\User;
use App\Services\PartnerAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The monthly absensi sheet's own arithmetic.
 *
 * The summary cases below are lifted straight off the reference sheet
 * (docs/screenshots/bisnisproses/excel-absensi.jpeg) — real rows, with the numbers that sheet
 * prints for them. That is the point: these formulas were reverse-engineered from it, so the
 * test that they are right has to be the sheet itself, not a restatement of the code.
 */
class PartnerAttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartnerAttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PartnerAttendanceService::class);
    }

    private function partner(array $attributes = []): Partner
    {
        return Partner::query()->create(array_merge([
            'name' => 'Partner Uji',
            'role' => Role::RIDER,
            'monthly_libur_quota' => 4,
            'is_active' => true,
        ], $attributes));
    }

    /** @return array<string, array{0:int,1:int,2:int,3:int,4:int,5:int,6:float}> */
    public static function referenceRowProvider(): array
    {
        // name => [hadir, libur, sakit, late, quota, expected over_quota, expected rate]
        return [
            'AGUNG (row 1)' => [23, 5, 0, 3, 4, 1, 88.5],
            'KHAIFAL (row 2)' => [23, 8, 0, 0, 4, 4, 88.5],
            'Endi (row 3)' => [13, 18, 0, 0, 4, 14, 50.0],
            // Fewer days off than the quota, so the "over" column goes negative — the sheet
            // prints -2 here, it does not floor at zero.
            'ADIT (row 4)' => [28, 2, 0, 1, 4, -2, 107.7],
            'TENG SENG (row 9)' => [24, 3, 1, 3, 4, -1, 92.3],
            // Barely worked: 1/26 = 3.8%, which the sheet rounds to 4%.
            'RANGGA (row 12)' => [1, 30, 0, 0, 4, 26, 3.8],
            // Over 100% is real and deliberately uncapped — worked through their days off.
            'NOVAL (row 13)' => [28, 2, 0, 1, 4, -2, 107.7],
            'AZIS (row 20)' => [30, 1, 0, 0, 4, -3, 115.4],
        ];
    }

    #[DataProvider('referenceRowProvider')]
    public function test_the_summary_columns_match_the_reference_sheet(
        int $hadir,
        int $libur,
        int $sakit,
        int $late,
        int $quota,
        int $expectedOverQuota,
        float $expectedRate,
    ): void {
        $codes = array_merge(
            array_fill(0, $hadir, PartnerAttendanceCode::PRESENT),
            array_fill(0, $libur, PartnerAttendanceCode::DAY_OFF),
            array_fill(0, $sakit, PartnerAttendanceCode::SICK),
            array_fill(0, $late, PartnerAttendanceCode::LATE),
        );

        $summary = $this->service->summarise($codes, $quota);

        $this->assertSame($hadir, $summary['hadir']);
        $this->assertSame($libur, $summary['libur']);
        $this->assertSame($sakit, $summary['sakit']);
        $this->assertSame($late, $summary['late']);
        $this->assertSame($expectedOverQuota, $summary['over_quota']);
        $this->assertSame($expectedRate, $summary['attendance_rate']);
    }

    /** Blank cells are the absence of a code, so they count towards nothing at all. */
    public function test_unrecorded_days_are_counted_as_nothing(): void
    {
        $summary = $this->service->summarise([null, null, PartnerAttendanceCode::PRESENT, null], 4);

        $this->assertSame(1, $summary['hadir']);
        $this->assertSame(0, $summary['libur']);
        $this->assertSame(0, $summary['sakit']);
        $this->assertSame(0, $summary['late']);
    }

    public function test_the_sheet_has_one_column_per_day_of_the_selected_month(): void
    {
        $this->partner(['nik' => '240001', 'name' => 'AGUNG']);

        // February 2026 has 28 days; the column count must follow the month, not a fixed 31.
        $february = $this->service->monthlySheet(Carbon::parse('2026-02-10'));
        $this->assertCount(28, $february->first()['codes']);

        $august = $this->service->monthlySheet(Carbon::parse('2026-08-10'));
        $this->assertCount(31, $august->first()['codes']);
    }

    public function test_entries_land_on_the_right_day_and_only_within_the_month(): void
    {
        $partner = $this->partner(['nik' => '240001']);
        $recorder = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->service->setCode($partner, Carbon::parse('2026-08-05'), PartnerAttendanceCode::PRESENT, $recorder);
        $this->service->setCode($partner, Carbon::parse('2026-08-06'), PartnerAttendanceCode::DAY_OFF, $recorder);
        // Neighbouring months must not bleed into August's grid or its totals.
        $this->service->setCode($partner, Carbon::parse('2026-07-31'), PartnerAttendanceCode::PRESENT, $recorder);
        $this->service->setCode($partner, Carbon::parse('2026-09-01'), PartnerAttendanceCode::PRESENT, $recorder);

        $row = $this->service->monthlySheet(Carbon::parse('2026-08-15'))->first();

        $this->assertSame(PartnerAttendanceCode::PRESENT, $row['codes'][5]);
        $this->assertSame(PartnerAttendanceCode::DAY_OFF, $row['codes'][6]);
        $this->assertNull($row['codes'][7]);
        $this->assertSame(1, $row['summary']['hadir']);
        $this->assertSame(1, $row['summary']['libur']);
    }

    public function test_setting_a_code_twice_updates_the_same_cell_rather_than_adding_one(): void
    {
        $partner = $this->partner();
        $date = Carbon::parse('2026-08-05');

        $this->service->setCode($partner, $date, PartnerAttendanceCode::PRESENT);
        $this->service->setCode($partner, $date, PartnerAttendanceCode::SICK);

        $this->assertSame(1, PartnerAttendanceEntry::query()->count());
        $this->assertSame(PartnerAttendanceCode::SICK, PartnerAttendanceEntry::query()->first()->code);
    }

    public function test_a_null_code_clears_the_cell(): void
    {
        $partner = $this->partner();
        $date = Carbon::parse('2026-08-05');

        $this->service->setCode($partner, $date, PartnerAttendanceCode::PRESENT);
        $this->service->setCode($partner, $date, null);

        $this->assertSame(0, PartnerAttendanceEntry::query()->count());
        $this->assertNull($this->service->monthlySheet($date)->first()['codes'][5]);
    }

    public function test_the_sheet_is_ordered_by_nik_with_partners_who_have_none_last(): void
    {
        $this->partner(['nik' => '240002', 'name' => 'KHAIFAL']);
        $this->partner(['nik' => null, 'name' => 'IPAN']);
        $this->partner(['nik' => '240001', 'name' => 'AGUNG']);
        $this->partner(['nik' => null, 'name' => 'ARYA']);

        $names = $this->service->monthlySheet(Carbon::parse('2026-08-01'))
            ->map(fn (array $row): string => $row['partner']->name)
            ->all();

        $this->assertSame(['AGUNG', 'KHAIFAL', 'ARYA', 'IPAN'], $names);
    }

    public function test_inactive_partners_are_left_off_the_sheet(): void
    {
        $this->partner(['name' => 'Masih Aktif']);
        $this->partner(['name' => 'Sudah Berhenti', 'is_active' => false]);

        $names = $this->service->monthlySheet(Carbon::parse('2026-08-01'))
            ->map(fn (array $row): string => $row['partner']->name)
            ->all();

        $this->assertSame(['Masih Aktif'], $names);
    }

    public function test_the_role_filter_narrows_the_sheet_to_one_heading(): void
    {
        $this->partner(['name' => 'Rider Satu', 'role' => Role::RIDER]);
        $this->partner(['name' => 'Staff Satu', 'role' => Role::STAFF]);

        $riders = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::RIDER);
        $all = $this->service->monthlySheet(Carbon::parse('2026-08-01'));

        $this->assertSame(['Rider Satu'], $riders->map(fn ($r) => $r['partner']->name)->all());
        $this->assertCount(2, $all);
    }

    /** A quota of zero must not turn the percentage into a division-by-zero crash. */
    public function test_a_zero_working_day_config_does_not_divide_by_zero(): void
    {
        config(['soul.attendance_working_days' => 0]);

        $summary = $this->service->summarise([PartnerAttendanceCode::PRESENT], 4);

        $this->assertSame(100.0, $summary['attendance_rate']);
    }
}
