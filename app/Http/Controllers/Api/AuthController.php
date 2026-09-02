<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\LoginWithPinRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/**
 * docs/04 §Auth. The server alone decides `role` — a client-supplied `role` field is never
 * read anywhere in this class, so it cannot be forged from the request body.
 */
class AuthController extends Controller
{
    private const PIN_MAX_FAILURES = 5;

    private const PIN_LOCKOUT_MINUTES = 15;

    public function login(LoginRequest $request): JsonResponse
    {
        // The mobile client already normalises, but the server must not trust that. PhoneNumber
        // is the single authoritative rule, shared with the admin panel so an account created
        // through one can always sign in through the other.
        $phone = PhoneNumber::normalize($request->validated('phone'));

        $user = User::query()->where('phone_e164', $phone)->first();

        // Same generic message whether the phone doesn't exist, the password is wrong, or the
        // account is inactive — never reveal which, to a caller that isn't authenticated yet.
        if (! $user || ! $user->is_active || ! Hash::check($request->validated('password'), $user->password)) {
            abort(401, 'Nomor HP atau kata sandi salah.');
        }

        $token = $user->createToken($request->validated('device_name'))->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => (new UserResource($user))->resolve($request),
            ],
        ]);
    }

    /**
     * `POST /auth/login-pin` — the optional second way in, for staff who type a 6-digit PIN on a
     * numeric keypad rather than a password on a phone keyboard in the field.
     *
     * A PIN is a far weaker secret than a password: six digits is a million combinations, and a
     * plain rate limit on the route is shared across everyone behind one mobile carrier NAT. So
     * the real defence is per-account: five wrong PINs locks THAT account's PIN route for fifteen
     * minutes, and the password route is left untouched so a locked-out user is never shut out of
     * their own account.
     */
    public function loginWithPin(LoginWithPinRequest $request): JsonResponse
    {
        $phone = PhoneNumber::normalize($request->validated('phone'));
        $user = User::query()->where('phone_e164', $phone)->first();

        // Same generic message for every rejection, as in login(): the caller is unauthenticated,
        // so it must not learn whether the phone exists or whether a PIN is even set on it.
        $reject = fn () => abort(401, 'Nomor HP atau PIN salah.');

        if (! $user || ! $user->is_active || ! $user->login_pin_hash) {
            $reject();
        }

        if ($user->login_pin_locked_until && $user->login_pin_locked_until->isFuture()) {
            abort(429, 'Terlalu banyak percobaan PIN. Coba lagi nanti atau masuk dengan kata sandi.');
        }

        if (! Hash::check($request->validated('pin'), $user->login_pin_hash)) {
            $failures = $user->login_pin_failures + 1;

            $user->forceFill([
                'login_pin_failures' => $failures,
                'login_pin_locked_until' => $failures >= self::PIN_MAX_FAILURES
                    ? now()->addMinutes(self::PIN_LOCKOUT_MINUTES)
                    : null,
            ])->save();

            $reject();
        }

        $user->forceFill(['login_pin_failures' => 0, 'login_pin_locked_until' => null])->save();

        $token = $user->createToken($request->validated('device_name'))->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => (new UserResource($user))->resolve($request),
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        // Only the token used for THIS request — logging out one device must not revoke
        // every other device the user is signed in on (BYOD, spec §14 Q8).
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
