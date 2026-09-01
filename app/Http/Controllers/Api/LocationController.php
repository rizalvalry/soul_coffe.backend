<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /locations` (docs/04). Role scoping applied at the query level (docs/02 §2.2): a Staff
 * user sees only the location(s) they are assigned to today; every other role sees all of them.
 */
class LocationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Location::query()->orderBy('name');

        if ($user->role === Role::STAFF) {
            $query->whereIn('id', function ($sub) use ($user): void {
                $sub->select('location_id')
                    ->from('staff_assignments')
                    ->where('user_id', $user->id)
                    ->whereDate('operating_date', now()->toDateString());
            });
        }

        return LocationResource::collection($query->get());
    }
}
