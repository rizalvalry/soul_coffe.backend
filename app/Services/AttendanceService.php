<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\StaffAttendanceWindow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private readonly EventPublisher $events) {}

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

        return DB::transaction(function () use ($user, $date): Attendance {
            $attendance = Attendance::query()->create([
                'operating_date' => $date,
                'user_id' => $user->id,
                // Stored, not joined — a later role change must not rewrite history.
                'role' => $user->role,
                // Server clock (R16).
                'clocked_in_at' => now(),
            ]);

            // Only the FIRST clock-in of the day publishes — the early return above means a
            // repeat tap never reaches here, so nobody is buzzed twice for one shift.
            $this->events->publish(
                'AttendanceClockedIn',
                'Absen masuk',
                sprintf('%s (%s) absen pukul %s.', $user->name, $user->role->label(), $attendance->clocked_in_at->format('H:i')),
                ['role.ADMINISTRATOR', 'role.FINANCE'],
                $this->supervisorIds(),
                null,
                null,
            );

            return $attendance;
        });
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

        return DB::transaction(function () use ($barista, $date): StaffAttendanceWindow {
            // firstOrCreate, not create: two baristas at the same kitchen both pressing this is a
            // race with an obvious right answer — the gate is open either way, and whoever got
            // there first stays recorded as the one who opened it.
            $window = StaffAttendanceWindow::query()->firstOrCreate(
                ['operating_date' => $date],
                ['opened_by' => $barista->id, 'opened_at' => now()],
            );

            // Published only by the barista who actually opened the gate. The loser of the race
            // gets the same window back and must not send a second round of notifications.
            if ($window->wasRecentlyCreated) {
                $this->events->publish(
                    'StaffAttendanceWindowOpened',
                    'Absen staff dibuka',
                    sprintf('%s sudah membuka absen. Silakan absen untuk mulai bertugas.', $barista->name),
                    ['role.STAFF', 'role.ADMINISTRATOR'],
                    $this->clockingStaffIds(),
                );
            }

            return $window;
        });
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

    /** Who is told that somebody clocked in: the roles that supervise the day. @return array<int,int> */
    private function supervisorIds(): array
    {
        return User::query()
            ->whereIn('role', [Role::ADMINISTRATOR, Role::FINANCE])
            ->where('is_active', true)
            ->pluck('id')
            ->all();
    }

    /** Every active staff member waiting on the gate. @return array<int,int> */
    private function clockingStaffIds(): array
    {
        return User::query()
            ->where('role', Role::STAFF)
            ->where('is_active', true)
            ->pluck('id')
            ->all();
    }
}
