<?php

namespace Tests\Feature;

use App\Enums\AttendanceCode;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\AttendanceMark;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\AttendanceSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The monthly absensi sheet: where each cell comes from, and the arithmetic to its right.
 *
 * The integration tests are the point of this file. The first version of this report read only
 * its own hand-entered table, so a staff member who had absen from their phone still showed an
 * empty cell — the office was re-typing facts the system already held. These tests pin the fix:
 * a clock-in appears on the sheet by itself, a manual code overrides it, and clearing the manual
 * code hands the cell back to the clock-in rather than erasing it.
 *
 * The summary cases are lifted straight off the reference sheet
 * (docs/screenshots/bisnisproses/excel-absensi.jpeg) — real rows, with the numbers that sheet
 * prints for them, because the formulas were reverse-engineered from it.
 */
class AttendanceSheetServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceSheetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AttendanceSheetService::class);
    }

    private function employee(Role $role = Role::RIDER, array $attributes = []): User
    {
        return User::factory()->role($role)->create($attributes);
    }

    // ── Provenance: app clock-in vs office entry ─────────────────────────

    public function test_a_clock_in_from_the_app_fills_the_cell_by_itself(): void
    {
        $staff = $this->employee(Role::STAFF);

        Attendance::create([
            'operating_date' => '2026-08-05',
            'user_id' => $staff->id,
            'role' => Role::STAFF,
            'clocked_in_at' => Carbon::parse('2026-08-05 07:12'),
        ]);

        $row = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::STAFF)->firstOrFail();

        $this->assertSame(AttendanceCode::PRESENT, $row['cells'][5]['code']);
        $this->assertSame('app', $row['cells'][5]['source']);
        // The clock-in time rides along, so the sheet can show where the M came from.
        $this->assertSame('07:12', $row['cells'][5]['time']);
        $this->assertSame(1, $row['summary']['hadir']);
        // Nothing was written to the manual table to make that happen.
        $this->assertSame(0, AttendanceMark::query()->count());
    }

    /** The real mobile flow, not a hand-built row: absen through the service the API calls. */
    public function test_the_actual_mobile_absen_flow_lands_on_the_sheet(): void
    {
        $barista = $this->employee(Role::BARISTA);

        app(AttendanceService::class)->clockIn($barista, Carbon::parse('2026-08-11'));

        $row = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::BARISTA)->firstOrFail();

        $this->assertSame(AttendanceCode::PRESENT, $row['cells'][11]['code']);
        $this->assertSame('app', $row['cells'][11]['source']);
    }

    public function test_a_manual_code_overrides_the_clock_in_and_is_marked_manual(): void
    {
        $staff = $this->employee(Role::STAFF);

        Attendance::create([
            'operating_date' => '2026-08-05',
            'user_id' => $staff->id,
            'role' => Role::STAFF,
            'clocked_in_at' => Carbon::parse('2026-08-05 07:12'),
        ]);

        $this->service->setCode($staff, Carbon::parse('2026-08-05'), AttendanceCode::LATE);

        $row = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::STAFF)->firstOrFail();

        $this->assertSame(AttendanceCode::LATE, $row['cells'][5]['code']);
        $this->assertSame('manual', $row['cells'][5]['source']);
        // The override moves the count out of hadir and into the late column.
        $this->assertSame(0, $row['summary']['hadir']);
        $this->assertSame(1, $row['summary']['late']);
    }

    /** You can annotate a clock-in; you cannot delete it. */
    public function test_clearing_a_manual_code_hands_the_cell_back_to_the_clock_in(): void
    {
        $staff = $this->employee(Role::STAFF);

        Attendance::create([
            'operating_date' => '2026-08-05',
            'user_id' => $staff->id,
            'role' => Role::STAFF,
            'clocked_in_at' => Carbon::parse('2026-08-05 07:12'),
        ]);

        $date = Carbon::parse('2026-08-05');
        $this->service->setCode($staff, $date, AttendanceCode::SICK);
        $this->service->setCode($staff, $date, null);

        $row = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::STAFF)->firstOrFail();

        $this->assertSame(AttendanceCode::PRESENT, $row['cells'][5]['code']);
        $this->assertSame('app', $row['cells'][5]['source']);
        $this->assertSame(0, AttendanceMark::query()->count());
        // And the clock-in itself was never touched.
        $this->assertSame(1, Attendance::query()->count());
    }

    /**
     * Riders cannot absen from the app at all (AttendanceService::CLOCKING_ROLES is Barista and
     * Staff), which is why the manual sheet exists for them in the first place.
     */
    public function test_a_role_that_cannot_clock_in_is_filled_entirely_by_hand(): void
    {
        $rider = $this->employee(Role::RIDER);

        $this->service->setCode($rider, Carbon::parse('2026-08-03'), AttendanceCode::PRESENT);

        $row = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::RIDER)->firstOrFail();

        $this->assertSame(AttendanceCode::PRESENT, $row['cells'][3]['code']);
        $this->assertSame('manual', $row['cells'][3]['source']);
        $this->assertNull($row['cells'][4]['code']);
    }

    // ── Summary columns ──────────────────────────────────────────────────

    /** @return array<string, array{0:int,1:int,2:int,3:int,4:int,5:int,6:float}> */
    public static function referenceRowProvider(): array
    {
        // name => [hadir, libur, sakit, late, quota, expected over_quota, expected rate]
        return [
            'AGUNG (row 1)' => [23, 5, 0, 3, 4, 1, 88.5],
            'Endi (row 3)' => [13, 18, 0, 0, 4, 14, 50.0],
            // Fewer days off than the quota, so the "over" column goes negative — the sheet
            // prints -2 here, it does not floor at zero.
            'ADIT (row 4)' => [28, 2, 0, 1, 4, -2, 107.7],
            'TENG SENG (row 9)' => [24, 3, 1, 3, 4, -1, 92.3],
            // Barely worked: 1/26 = 3.8%, which the sheet rounds to 4%.
            'RANGGA (row 12)' => [1, 30, 0, 0, 4, 26, 3.8],
            // Over 100% is real and deliberately uncapped — worked through their days off.
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
        $cell = fn (?AttendanceCode $code): array => ['code' => $code, 'source' => 'manual', 'time' => null];

        $cells = array_merge(
            array_fill(0, $hadir, $cell(AttendanceCode::PRESENT)),
            array_fill(0, $libur, $cell(AttendanceCode::DAY_OFF)),
            array_fill(0, $sakit, $cell(AttendanceCode::SICK)),
            array_fill(0, $late, $cell(AttendanceCode::LATE)),
        );

        $summary = $this->service->summarise($cells, $quota);

        $this->assertSame($hadir, $summary['hadir']);
        $this->assertSame($libur, $summary['libur']);
        $this->assertSame($sakit, $summary['sakit']);
        $this->assertSame($late, $summary['late']);
        $this->assertSame($expectedOverQuota, $summary['over_quota']);
        $this->assertSame($expectedRate, $summary['attendance_rate']);
    }

    /** A clock-in counts towards hadir exactly like a hand-entered M. */
    public function test_hadir_counts_clock_ins_and_manual_marks_alike(): void
    {
        $staff = $this->employee(Role::STAFF, ['monthly_libur_quota' => 4]);

        foreach ([1, 2, 3] as $day) {
            Attendance::create([
                'operating_date' => sprintf('2026-08-%02d', $day),
                'user_id' => $staff->id,
                'role' => Role::STAFF,
                'clocked_in_at' => Carbon::parse(sprintf('2026-08-%02d 07:00', $day)),
            ]);
        }

        $this->service->setCode($staff, Carbon::parse('2026-08-10'), AttendanceCode::PRESENT);

        $row = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::STAFF)->firstOrFail();

        $this->assertSame(4, $row['summary']['hadir']);
    }

    public function test_unrecorded_days_are_counted_as_nothing(): void
    {
        $blank = ['code' => null, 'source' => null, 'time' => null];
        $present = ['code' => AttendanceCode::PRESENT, 'source' => 'app', 'time' => '07:00'];

        $summary = $this->service->summarise([$blank, $blank, $present, $blank], 4);

        $this->assertSame(1, $summary['hadir']);
        $this->assertSame(0, $summary['libur']);
    }

    /** A quota of zero must not turn the percentage into a division-by-zero crash. */
    public function test_a_zero_working_day_config_does_not_divide_by_zero(): void
    {
        config(['soul.attendance_working_days' => 0]);

        $summary = $this->service->summarise(
            [['code' => AttendanceCode::PRESENT, 'source' => 'manual', 'time' => null]],
            4,
        );

        $this->assertSame(100.0, $summary['attendance_rate']);
    }

    // ── Shape of the sheet ───────────────────────────────────────────────

    public function test_the_sheet_has_one_column_per_day_of_the_selected_month(): void
    {
        $this->employee(Role::RIDER);

        // February 2026 has 28 days; the column count must follow the month, not a fixed 31.
        $this->assertCount(28, $this->service->monthlySheet(Carbon::parse('2026-02-10'))->first()['cells']);
        $this->assertCount(31, $this->service->monthlySheet(Carbon::parse('2026-08-10'))->first()['cells']);
    }

    public function test_neighbouring_months_do_not_bleed_into_the_grid(): void
    {
        $rider = $this->employee(Role::RIDER);

        $this->service->setCode($rider, Carbon::parse('2026-07-31'), AttendanceCode::PRESENT);
        $this->service->setCode($rider, Carbon::parse('2026-09-01'), AttendanceCode::PRESENT);
        $this->service->setCode($rider, Carbon::parse('2026-08-15'), AttendanceCode::PRESENT);

        $row = $this->service->monthlySheet(Carbon::parse('2026-08-10'), Role::RIDER)->firstOrFail();

        $this->assertSame(1, $row['summary']['hadir']);
        $this->assertSame(AttendanceCode::PRESENT, $row['cells'][15]['code']);
    }

    public function test_setting_a_code_twice_updates_the_same_cell_rather_than_adding_one(): void
    {
        $rider = $this->employee(Role::RIDER);
        $date = Carbon::parse('2026-08-05');

        $this->service->setCode($rider, $date, AttendanceCode::PRESENT);
        $this->service->setCode($rider, $date, AttendanceCode::SICK);

        $this->assertSame(1, AttendanceMark::query()->count());
        $this->assertSame(AttendanceCode::SICK, AttendanceMark::query()->firstOrFail()->code);
    }

    public function test_the_sheet_is_ordered_by_nik_with_people_who_have_none_last(): void
    {
        $this->employee(Role::RIDER, ['nik' => '240002', 'name' => 'KHAIFAL']);
        $this->employee(Role::RIDER, ['nik' => null, 'name' => 'IPAN']);
        $this->employee(Role::RIDER, ['nik' => '240001', 'name' => 'AGUNG']);
        $this->employee(Role::RIDER, ['nik' => null, 'name' => 'ARYA']);

        $names = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::RIDER)
            ->map(fn (array $row): string => $row['user']->name)
            ->all();

        $this->assertSame(['AGUNG', 'KHAIFAL', 'ARYA', 'IPAN'], $names);
    }

    public function test_inactive_employees_are_left_off_the_sheet(): void
    {
        $this->employee(Role::RIDER, ['name' => 'Masih Aktif']);
        $this->employee(Role::RIDER, ['name' => 'Sudah Berhenti', 'is_active' => false]);

        $names = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::RIDER)
            ->map(fn (array $row): string => $row['user']->name)
            ->all();

        $this->assertSame(['Masih Aktif'], $names);
    }

    public function test_the_role_filter_narrows_the_sheet_to_one_heading(): void
    {
        $this->employee(Role::RIDER, ['name' => 'Rider Satu']);
        $this->employee(Role::STAFF, ['name' => 'Staff Satu']);

        $riders = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::RIDER);

        $this->assertSame(['Rider Satu'], $riders->map(fn ($r) => $r['user']->name)->all());
        $this->assertCount(2, $this->service->monthlySheet(Carbon::parse('2026-08-01')));
    }

    /** The quota is per person now, so two people on the same sheet can differ. */
    public function test_each_person_carries_their_own_libur_quota(): void
    {
        $generous = $this->employee(Role::RIDER, ['name' => 'A', 'nik' => '1', 'monthly_libur_quota' => 8]);
        $standard = $this->employee(Role::RIDER, ['name' => 'B', 'nik' => '2', 'monthly_libur_quota' => 4]);

        foreach ([$generous, $standard] as $person) {
            foreach ([1, 2, 3, 4, 5] as $day) {
                $this->service->setCode($person, Carbon::parse(sprintf('2026-08-%02d', $day)), AttendanceCode::DAY_OFF);
            }
        }

        $rows = $this->service->monthlySheet(Carbon::parse('2026-08-01'), Role::RIDER);

        $this->assertSame(-3, $rows->firstWhere('user.name', 'A')['summary']['over_quota']);
        $this->assertSame(1, $rows->firstWhere('user.name', 'B')['summary']['over_quota']);
    }
}
