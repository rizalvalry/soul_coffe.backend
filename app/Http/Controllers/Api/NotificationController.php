<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** docs/04 §Notifications, docs/02 §8. Every notification here belongs to the caller — never another user's inbox. */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest('id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return NotificationResource::collection($query->get());
    }

    public function markRead(Request $request, AppNotification $notification): Response
    {
        // Ownership check at the query/record level, never inferred from a hidden UI — a
        // notification belongs to exactly one user, and no other caller may touch its read_at.
        if ($notification->user_id !== $request->user()->id) {
            abort(403, 'Anda tidak berhak menandai notifikasi ini.');
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->noContent();
    }

    /**
     * Clears the whole badge in one write instead of one PATCH per row — a bell that has
     * accumulated dozens of unread rows (a busy shift, a phone left signed out for a day) must
     * not force the user to tap each one just to make the count go away.
     *
     * Scoped to `$request->user()->id` at the query level, same as index() — there is no
     * "mark everyone's notifications read" version of this endpoint.
     */
    public function markAllRead(Request $request): Response
    {
        AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->noContent();
    }
}
