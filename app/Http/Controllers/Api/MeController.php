<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\StaffAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /me` — the login `user` object plus `today_location_name` (docs/04). */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = (new UserResource($user))->resolve($request);

        $todayLocationName = null;

        if ($user->role === Role::STAFF) {
            $assignment = StaffAssignment::query()
                ->with('location')
                ->where('user_id', $user->id)
                ->whereDate('operating_date', now()->toDateString())
                ->first();

            $todayLocationName = $assignment?->location?->name;
        }

        $data['today_location_name'] = $todayLocationName;

        return response()->json(['data' => $data]);
    }
}
