<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\StaffAttendanceWindow;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The only writer of `attendances` and `staff_attendance_windows`.
 *
 * The rule this exists to enforce is a sequence, not a permission: a Barista clocks in, brews,
 * and only then opens the gate that lets Staff clock in — because until there is coffee in the
 * showcase there is nothing for a staff member to go out and sell. Every check below is a step
 * in that sequence, and the sequence is the feature.
 */
class AttendanceService
{
    /**
     * Roles that clock in at all. Everyone else (Finance, Rider, Administrator, Content Creator)
     * has no shift to start — an absen button would be meaningless for them.
     */
    private const CLOCKING_ROLES = [Role::BARISTA, Role::STAFF];

    /**
     * Idempotent: a second tap on the same day returns the existing row rather than erroring.
     *
     * A double-tap on a phone with a slow connection is the normal case, not misuse, and the one
     * thing that must never happen is a person's start-of-work time silently moving later
     * because they pressed the button twice.
     */
    public function clockIn(User $user, ?Carbon $operatingDate = null): Attendance
    {
        $date = ($operatingDate ?? Carbon::today())->toDateString();

        if (! in_array($user->role, self::CLOCKING_ROLES, true)) {
            throw new RuntimeException('Role ini tidak memiliki absen.');
        }

        if ($user->role === Role::STAFF && ! $this->isStaffWindowOpen($operatingDate)) {
            throw new RuntimeException(
                'Absen staff belum dibuka. Tunggu barista membuka absen setelah kopi siap.'
            );
        }

        $existing = Attendance::query()
            ->where('user_id', $user->id)
            ->whereDate('operating_date', $date)
            ->first();

        if ($existing) {
            return $existing;
        }

        return Attendance::query()->create([
            'operating_date' => $date,
            'user_id' => $user->id,
            // Stored, not joined — a later role change must not rewrite history.
            'role' => $user->role,
            // Server clock (R16).
            'clocked_in_at' => now(),
        ]);
    }

    /**
     * Barista opens the day's absen for every staff member.
     *
     * Requires the barista to have clocked in first, and not as ceremony: "open absen" asserts
     * that the coffee is ready, which is only meaningful coming from someone who has actually
     * started their own shift.
     */
    public function openStaffWindow(User $barista, ?Carbon $operatingDate = null): StaffAttendanceWindow
    {
        $date = ($operatingDate ?? Carbon::today())->toDateString();

        if ($barista->role !== Role::BARISTA) {
            throw new RuntimeException('Hanya barista yang dapat membuka absen staff.');
        }

        $hasClockedIn = Attendance::query()
            ->where('user_id', $barista->id)
            ->whereDate('operating_date', $date)
            ->exists();

        if (! $hasClockedIn) {
            throw new RuntimeException('Absen dulu sebelum membuka absen staff.');
        }

        // firstOrCreate, not create: two baristas at the same kitchen both pressing this is a
        // race with an obvious right answer — the gate is open either way, and whoever got there
        // first stays recorded as the one who opened it.
        return StaffAttendanceWindow::query()->firstOrCreate(
            ['operating_date' => $date],
            ['opened_by' => $barista->id, 'opened_at' => now()],
        );
    }

    public function isStaffWindowOpen(?Carbon $operatingDate = null): bool
    {
        return StaffAttendanceWindow::query()
            ->whereDate('operating_date', ($operatingDate ?? Carbon::today())->toDateString())
            ->exists();
    }

    public function hasClockedIn(User $user, ?Carbon $operatingDate = null): bool
    {
        return Attendance::query()
            ->where('user_id', $user->id)
            ->whereDate('operating_date', ($operatingDate ?? Carbon::today())->toDateString())
            ->exists();
    }
}
