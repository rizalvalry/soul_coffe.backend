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
 *
 * Two sign-in credentials exist, and they are EXCLUSIVE per account:
 *
 *   - no login PIN set  → the password route works, the PIN route answers 409 PIN_NOT_SET
 *   - login PIN set     → the PIN route works, the password route answers 409 PIN_REQUIRED
 *
 * The PIN is a replacement, not an addition. Once someone has created one, their password stops
 * opening the app until an Administrator resets it (PinResetRequestController → Filament), which
 * also clears the PIN. The password itself still guards PIN creation and the reset request, so it
 * never becomes a dead credential — it just stops being a second front door.
 *
 * SCOPE, STATED PLAINLY: this rule governs the mobile API only. The Filament panel
 * (App\Filament\Auth\Login) still authenticates ADMINISTRATOR and CONTENT_CREATOR by password,
 * because the panel has no PIN entry and gating it on one would lock those two roles out of the
 * only surface they have. So an administrator who sets a mobile PIN has closed the app to their
 * password but not /admin. That is a deliberate boundary, not an oversight — if the intent is to
 * retire a password entirely, rotate it in the panel as well.
 */
class AuthController extends Controller
{
    private const PIN_MAX_FAILURES = 5;

    private const PIN_LOCKOUT_MINUTES = 15;

    /** Machine-readable reason codes the mobile client switches on. */
    public const CODE_PIN_REQUIRED = 'PIN_REQUIRED';

    public const CODE_PIN_NOT_SET = 'PIN_NOT_SET';

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

        // Checked only AFTER the password matched: a caller who does not know the password learns
        // nothing about whether a PIN exists, so this reveals no more than a successful login would.
        if ($user->login_pin_hash !== null) {
            return response()->json([
                'message' => 'Akun ini sudah memiliki PIN. Masuk dengan PIN Anda.',
                'code' => self::CODE_PIN_REQUIRED,
            ], 409);
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
     * `POST /auth/login-pin` — the way in once a PIN exists: a 6-digit PIN on a numeric keypad
     * rather than a password on a phone keyboard in the field.
     *
     * A PIN is a far weaker secret than a password: six digits is a million combinations, and a
     * plain rate limit on the route is shared across everyone behind one mobile carrier NAT. So
     * the real defence is per-account: five wrong PINs locks THAT account's PIN route for fifteen
     * minutes. Because the password route is closed while a PIN exists, the lockout is a full
     * fifteen-minute wait — the "lupa PIN" request is the only faster exit, and it goes through
     * an Administrator on purpose.
     *
     * One deliberate disclosure: an active account WITHOUT a PIN answers 409 PIN_NOT_SET instead
     * of the generic 401. The device that just had its PIN reset by an Administrator needs to know
     * to fall back to the password form; without this it would show "PIN salah" forever. The
     * enumeration cost is accepted: it only tells a caller who already knows a valid phone number
     * that its owner signs in with a password, behind a 5/min throttle.
     */
    public function loginWithPin(LoginWithPinRequest $request): JsonResponse
    {
        $phone = PhoneNumber::normalize($request->validated('phone'));
        $user = User::query()->where('phone_e164', $phone)->first();

        $reject = fn () => abort(401, 'Nomor HP atau PIN salah.');

        if (! $user || ! $user->is_active) {
            $reject();
        }

        if ($user->login_pin_hash === null) {
            return response()->json([
                'message' => 'Akun ini belum memiliki PIN. Masuk dengan kata sandi.',
                'code' => self::CODE_PIN_NOT_SET,
            ], 409);
        }

        if ($user->login_pin_locked_until && $user->login_pin_locked_until->isFuture()) {
            abort(429, 'Terlalu banyak percobaan PIN. Coba lagi dalam 15 menit, atau ajukan reset PIN ke Administrator.');
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
