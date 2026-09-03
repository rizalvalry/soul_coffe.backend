<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

/**
 * Absen — the barista/staff clock-in and the gate between them.
 *
 * `status` is the endpoint the mobile app leans on hardest: it is what decides whether the staff
 * absen button renders enabled or disabled, so it answers "can I press this, and if not why" in
 * one call rather than making the client infer it from a 422.
 */
class AttendanceController extends Controller implements HasMiddleware
{
    public function __construct(private readonly AttendanceService $service) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            // Only a barista can open the day for everyone else.
            new Middleware('role:BARISTA', only: ['open']),
        ];
    }

    /**
     * Clock in. Idempotent — a second tap returns the same row rather than an error, because a
     * double-tap on a bad connection is ordinary and must never move someone's start time.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $attendance = $this->service->clockIn($request->user());
        } catch (RuntimeException $e) {
            // 422 rather than 403: the caller is allowed to absen in principle, the day just
            // isn't ready for them yet, and the message says so.
            abort(422, $e->getMessage());
        }

        return (new AttendanceResource($attendance))
            ->response()
            ->setStatusCode($attendance->wasRecentlyCreated ? 201 : 200);
    }

    /** Barista opens absen for every staff member today. */
    public function open(Request $request): JsonResponse
    {
        try {
            $window = $this->service->openStaffWindow($request->user());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'data' => [
                'operating_date' => $window->operating_date?->toDateString(),
                'opened_by' => $window->opened_by,
                'opened_at' => $window->opened_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Everything the app needs to render both buttons in one round trip.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $hasClockedIn = $this->service->hasClockedIn($user);
        $windowOpen = $this->service->isStaffWindowOpen();

        $isBarista = $user->role === Role::BARISTA;
        $isStaff = $user->role === Role::STAFF;

        return response()->json([
            'data' => [
                'operating_date' => now()->toDateString(),
                'has_clocked_in' => $hasClockedIn,
                'clocked_in_at' => Attendance::query()
                    ->where('user_id', $user->id)
                    ->whereDate('operating_date', now()->toDateString())
                    ->value('clocked_in_at')?->toIso8601String(),

                'staff_window_open' => $windowOpen,

                // A barista may always absen. A staff member may only once the gate is open —
                // and either way, not twice.
                'can_clock_in' => match (true) {
                    $hasClockedIn => false,
                    $isBarista => true,
                    $isStaff => $windowOpen,
                    default => false,
                },

                // The "Open Absen" button: barista only, after their own absen, and hidden once
                // the gate is already open.
                'can_open_staff_window' => $isBarista && $hasClockedIn && ! $windowOpen,

                // So the client can render the right copy for a disabled button instead of
                // hard-coding the reason.
                'blocked_reason' => match (true) {
                    $hasClockedIn => 'Sudah absen hari ini.',
                    $isStaff && ! $windowOpen => 'Menunggu barista membuka absen.',
                    ! $isBarista && ! $isStaff => 'Role ini tidak memiliki absen.',
                    default => null,
                },
            ],
        ]);
    }

    /**
     * Today's roll call. Barista and staff both see it — knowing who is already on shift is
     * ordinary shift information, not something to gate.
     */
    public function index(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        $attendances = Attendance::query()
            ->whereDate('operating_date', $date)
            ->with('user')
            ->orderBy('clocked_in_at')
            ->get();

        return response()->json([
            'data' => AttendanceResource::collection($attendances),
        ]);
    }
}
