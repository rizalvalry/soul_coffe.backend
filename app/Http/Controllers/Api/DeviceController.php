<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DevicePushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Push registrations for the calling user's device (docs/04 §Push).
 *
 * `store` is an upsert keyed on the token: re-registering the same device moves it to whoever is
 * signed in now (shared handsets — see the migration). `destroy` is called by the app on a
 * deliberate sign-out so a phone handed to a colleague stops receiving the previous user's events.
 *
 * Both are scoped to the caller. A user can never list, move, or delete another user's device.
 */
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // FCM tokens are opaque; the only sane validation is a length bound. 4096 is the
            // provider's documented ceiling, 512 is what the column holds.
            'token' => ['required', 'string', 'min:32', 'max:512'],
            'platform' => ['nullable', 'string', 'in:android,ios'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $registration = DevicePushToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? 'android',
                'device_name' => $validated['device_name'] ?? null,
                'last_seen_at' => now(),
                'failed_at' => null,
            ],
        );

        return response()->json([
            'data' => [
                'id' => $registration->id,
                'platform' => $registration->platform,
                'registered_at' => $registration->last_seen_at?->toIso8601String(),
            ],
        ], $registration->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): Response
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        // Only rows that belong to the caller. If the token has since been re-registered by
        // another user on the same phone, it is theirs now and this call is a no-op.
        DevicePushToken::query()
            ->where('token', $validated['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->noContent();
    }
}
