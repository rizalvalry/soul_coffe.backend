<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationPingRequest;
use App\Services\StaffLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Where the phone reports itself from.
 *
 * Staff only: the trail exists to explain a cart's day, and a rider's or barista's route is not
 * part of that story. Recording everyone's movement because it was easy would be surveillance
 * with no question behind it.
 *
 * Answers 202 with a count rather than the stored rows. The client has nothing to do with them,
 * and a ping upload must be the cheapest request in the API — it runs on a phone battery in a
 * pocket all day.
 *
 * No idempotency key: a replayed batch is harmless. The service keeps a ping only when it says
 * something new, so a duplicate upload collapses into zero writes on its own.
 */
class LocationPingController extends Controller implements HasMiddleware
{
    public function __construct(private readonly StaffLocationService $locations) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('role:STAFF'),
            // Generous, because the app batches: this bounds abuse without ever throttling a
            // device that flushes a backlog after a dead spot.
            new Middleware('throttle:60,1'),
        ];
    }

    public function store(StoreLocationPingRequest $request): JsonResponse
    {
        $written = $this->locations->recordBatch(
            staff: $request->user(),
            pings: $request->validated('pings'),
            deviceId: $request->validated('device_id'),
        );

        return response()->json([
            'data' => [
                'recorded' => $written,
                // Tells the client how often the server actually wants to hear from it, so the
                // reporting interval is a server decision rather than a hardcoded constant in a
                // shipped APK.
                'min_interval_seconds' => (int) config('soul.location_ping_min_interval_seconds', 45),
            ],
        ], 202);
    }
}
