<?php

namespace App\Services;

use App\Enums\AttendanceCode;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\AttendanceMark;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The monthly absensi sheet: one row per employee, one column per day, then the summary columns.
 *
 * TWO SOURCES, ONE CELL
 * ---------------------
 * Presence is not typed in for anyone who can clock in. `attendances` already holds what Barista
 * and Rider recorded from their own phones (server clock, R16), so an M appears on this sheet by
 * itself the moment they absen — that was the whole point of building the mobile absen flow, and
 * the first version of this report ignored it, which meant the office was re-typing facts the
 * system already had.
 *
 * A hand-entered `attendance_marks` row WINS over the derived M, because the codes it carries are
 * things no clock-in can express: libur, sakit, berangkat siang, or presence for RUNNER — a role
 * that cannot absen from the app at all (AttendanceService::CLOCKING_ROLES is Barista and Rider
 * only). Clearing a mark does not erase a clock-in; the cell simply falls back to what the person
 * actually recorded. You can annotate the fact, not delete it.
 *
 * Every cell therefore reports its provenance ('app' or 'manual'), and the UI shows the two
 * differently — a number in a payroll report should say where it came from.
 *
 * SUMMARY FORMULAS
 * ----------------
 * Read back out of the reference sheet by checking against its own printed numbers, row by row:
 *
 *   - "Lebih dari Jatah / Tidak Masuk" = Libur − Jatah Klibur. Row 1: 5 − 4 = 1 ✓. Row 3:
 *     18 − 4 = 14 ✓. Row 12: 30 − 4 = 26 ✓. It goes NEGATIVE when someone took fewer days off
 *     than they were owed (row 4 prints −2), so it is a signed number, not a floor-at-zero count.
 *   - "Presentase Kehadiran" = Hadir ÷ 26. Row 1: 23/26 = 88% ✓. Row 3: 13/26 = 50% ✓. Row 13:
 *     28/26 = 108% ✓ — over 100% is real and means the person worked on days they could have
 *     taken off, so it is deliberately NOT capped.
 *
 * 26 is the standard working-days divisor, kept in config (`soul.attendance_working_days`) rather
 * than inlined, because it is a payroll convention that can change without this code changing.
 */
class AttendanceSheetService
{
    /**
     * @return Collection<int, array{
     *     user: User,
     *     cells: array<int, array{code: AttendanceCode|null, source: string|null, time: string|null}>,
     *     summary: array{libur:int, sakit:int, late:int, hadir:int, quota:int, over_quota:int, attendance_rate:float}
     * }>
     */
    public function monthlySheet(Carbon $month, ?Role $role = null, ?array $userIds = null): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $users = User::query()
            ->when($userIds === null, fn ($q) => $q->where(function ($roster) use ($end): void {
                $roster->where('is_active', true)
                    ->orWhere(function ($historical) use ($end): void {
                        $historical->whereHas('employeeProfile', fn ($profile) => $profile->where('payroll_active', true))
                            ->where(fn ($joined) => $joined->whereNull('joined_at')
                                ->orWhereDate('joined_at', '<=', $end->toDateString()));
                    });
            }))
            ->when($userIds !== null, fn ($q) => $q->whereIn('id', $userIds))
            ->when($role, fn ($q) => $q->where('role', $role))
            // NIK order is the sheet's own order; people without one sort last by name rather
            // than dropping off the end of the report.
            ->orderByRaw('nik IS NULL, nik ASC')
            ->orderBy('name')
            ->get();

        $userIds = $users->pluck('id');

        $marks = AttendanceMark::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('user_id');

        $clockIns = Attendance::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('operating_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('user_id');

        $settings = DB::table('payroll_attendance_settings')->first();
        $lateAfter = $settings?->late_after ?? '09:00';
        $targetCups = (int) ($settings?->target_cups ?? 38);
        $closingCups = DB::table('rider_closings as closings')
            ->leftJoin('rider_closing_lines as lines', 'lines.rider_closing_id', '=', 'closings.id')
            ->leftJoin('products', 'products.id', '=', 'lines.product_id')
            ->whereIn('closings.rider_id', $userIds)
            ->whereBetween('closings.operating_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('closings.rider_id', 'closings.operating_date')
            ->selectRaw("closings.rider_id, closings.operating_date, SUM(CASE WHEN products.unit = 'cup' THEN lines.qty_sold ELSE 0 END) AS cups_sold")
            ->get()->groupBy('rider_id');

        return $users->map(function (User $user) use ($marks, $clockIns, $closingCups, $lateAfter, $targetCups, $end): array {
            $marksByDay = ($marks[$user->id] ?? collect())
                ->keyBy(fn (AttendanceMark $m) => (int) $m->entry_date->day);

            $clockInsByDay = ($clockIns[$user->id] ?? collect())
                ->keyBy(fn (Attendance $a) => (int) $a->operating_date->day);
            $cupsByDay = ($closingCups[$user->id] ?? collect())
                ->keyBy(fn ($closing) => (int) Carbon::parse($closing->operating_date)->day);

            $cells = [];
            for ($day = 1; $day <= $end->day; $day++) {
                $mark = $marksByDay[$day] ?? null;
                $clockIn = $clockInsByDay[$day] ?? null;

                if ($mark) {
                    $cells[$day] = ['code' => $mark->code, 'source' => 'manual', 'time' => null];
                } elseif ($clockIn) {
                    // Database timestamps are stored in the app timezone; compare the local WIB clock.
                    $localClock = Carbon::parse($clockIn->getRawOriginal('clocked_in_at'), config('app.timezone'))
                        ->timezone('Asia/Jakarta');
                    $time = $localClock->format('H:i');
                    $suggestLate = $clockIn->role === Role::STAFF && isset($cupsByDay[$day])
                        && $localClock->format('H:i:s') > $lateAfter.':00'
                        && (int) $cupsByDay[$day]->cups_sold < $targetCups;
                    $cells[$day] = [
                        'code' => $suggestLate ? AttendanceCode::LATE : AttendanceCode::PRESENT,
                        'source' => $suggestLate ? 'system' : 'app',
                        'time' => $time,
                    ];
                } else {
                    $cells[$day] = ['code' => null, 'source' => null, 'time' => null];
                }
            }

            return [
                'user' => $user,
                'cells' => $cells,
                'summary' => $this->summarise($cells, (int) $user->monthly_libur_quota),
            ];
        })->values();
    }

    /**
     * @param  array<int, array{code: AttendanceCode|null, source: string|null, time: string|null}>  $cells
     * @return array{libur:int, sakit:int, late:int, hadir:int, quota:int, over_quota:int, attendance_rate:float}
     */
    public function summarise(array $cells, int $quota): array
    {
        $count = function (AttendanceCode $wanted) use ($cells): int {
            return count(array_filter($cells, fn (array $cell) => ($cell['code'] ?? null) === $wanted));
        };

        $libur = $count(AttendanceCode::DAY_OFF);
        $hadir = $count(AttendanceCode::PRESENT);
        $workingDays = max(1, (int) config('soul.attendance_working_days', 26));

        return [
            'libur' => $libur,
            'sakit' => $count(AttendanceCode::SICK),
            'late' => $count(AttendanceCode::LATE),
            'absent' => $count(AttendanceCode::ABSENT),
            'hadir' => $hadir,
            'quota' => $quota,
            'over_quota' => $libur - $quota,
            'attendance_rate' => round(($hadir / $workingDays) * 100, 1),
        ];
    }

    /**
     * Writes one cell by hand.
     *
     * A null code removes the mark. Where the person clocked in that day the cell then shows M
     * again — the clock-in is theirs, and this sheet does not get to delete it.
     */
    public function setCode(User $user, Carbon $date, ?AttendanceCode $code, ?User $actor = null): void
    {
        if ($code === null) {
            AttendanceMark::query()
                ->where('user_id', $user->id)
                ->whereDate('entry_date', $date->toDateString())
                ->delete();

            return;
        }

        AttendanceMark::query()->updateOrCreate(
            ['user_id' => $user->id, 'entry_date' => $date->toDateString()],
            ['code' => $code, 'recorded_by' => $actor?->id],
        );
    }
}
