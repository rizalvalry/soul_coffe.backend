<?php

namespace App\Services;

use App\Enums\PartnerAttendanceCode;
use App\Enums\Role;
use App\Models\Partner;
use App\Models\PartnerAttendanceEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The monthly absensi sheet: the grid of codes, and the summary columns to its right.
 *
 * The summary formulas are not invented here — they were read back out of the reference sheet
 * (docs/screenshots/bisnisproses/excel-absensi.jpeg) by checking them against its own printed
 * numbers, row by row:
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
 *
 * Computed here rather than in the Filament page for the same reason DashboardMetricsService
 * exists: a Livewire component is near-impossible to unit test, and these numbers decide what
 * partners get paid.
 */
class PartnerAttendanceService
{
    /**
     * Every partner's row for one month, in sheet order.
     *
     * @return Collection<int, array{
     *     partner: Partner,
     *     codes: array<int, PartnerAttendanceCode|null>,
     *     summary: array{libur:int, sakit:int, late:int, hadir:int, quota:int, over_quota:int, attendance_rate:float}
     * }>
     */
    public function monthlySheet(Carbon $month, ?Role $role = null): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $partners = Partner::query()
            ->where('is_active', true)
            ->when($role, fn ($q) => $q->where('role', $role))
            // NIK order is the sheet's own order; partners without one (they exist — rows 24-27 of
            // the reference) sort last by name rather than vanishing off the end of the report.
            ->orderByRaw('nik IS NULL, nik ASC')
            ->orderBy('name')
            ->get();

        $entries = PartnerAttendanceEntry::query()
            ->whereIn('partner_id', $partners->pluck('id'))
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('partner_id');

        return $partners->map(function (Partner $partner) use ($entries, $start, $end): array {
            $byDay = ($entries[$partner->id] ?? collect())
                ->keyBy(fn (PartnerAttendanceEntry $e) => (int) $e->entry_date->day);

            $codes = [];
            for ($day = 1; $day <= $end->day; $day++) {
                $codes[$day] = $byDay[$day]->code ?? null;
            }

            return [
                'partner' => $partner,
                'codes' => $codes,
                'summary' => $this->summarise($codes, $partner->monthly_libur_quota),
            ];
        })->values();
    }

    /**
     * @param  array<int, PartnerAttendanceCode|null>  $codes
     * @return array{libur:int, sakit:int, late:int, hadir:int, quota:int, over_quota:int, attendance_rate:float}
     */
    public function summarise(array $codes, int $quota): array
    {
        $count = function (PartnerAttendanceCode $wanted) use ($codes): int {
            return count(array_filter($codes, fn (?PartnerAttendanceCode $c) => $c === $wanted));
        };

        $libur = $count(PartnerAttendanceCode::DAY_OFF);
        $hadir = $count(PartnerAttendanceCode::PRESENT);
        $workingDays = max(1, (int) config('soul.attendance_working_days', 26));

        return [
            'libur' => $libur,
            'sakit' => $count(PartnerAttendanceCode::SICK),
            'late' => $count(PartnerAttendanceCode::LATE),
            'hadir' => $hadir,
            'quota' => $quota,
            'over_quota' => $libur - $quota,
            'attendance_rate' => round(($hadir / $workingDays) * 100, 1),
        ];
    }

    /**
     * Writes one cell. A null code clears it — the sheet's blank means "nothing recorded", which
     * is the absence of a row rather than a fifth code (see the migration).
     */
    public function setCode(Partner $partner, Carbon $date, ?PartnerAttendanceCode $code, ?User $actor = null): void
    {
        if ($code === null) {
            PartnerAttendanceEntry::query()
                ->where('partner_id', $partner->id)
                ->whereDate('entry_date', $date->toDateString())
                ->delete();

            return;
        }

        PartnerAttendanceEntry::query()->updateOrCreate(
            ['partner_id' => $partner->id, 'entry_date' => $date->toDateString()],
            ['code' => $code, 'recorded_by' => $actor?->id],
        );
    }
}
