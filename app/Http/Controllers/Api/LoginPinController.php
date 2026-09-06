<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\EventPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The user's own login PIN (docs/04 §Auth).
 *
 * Setting or clearing a PIN requires the account password even though the caller already holds a
 * valid token. A token can be lifted from an unlocked phone; the password cannot. Without that
 * re-check, anyone who picked up a signed-in device could quietly mint themselves a 6-digit
 * credential that survives a remote token revocation.
 *
 * Creating a PIN ENDS EVERY SESSION, this one included. From that moment the password no longer
 * signs in (AuthController::login), so the only way back is the PIN — and the user proves they
 * can actually type it before they lose anything: the app shows "you will be signed out, sign in
 * with your PIN" and the very next screen is the PIN prompt. Revoking server-side rather than
 * trusting the app to log out is what makes this a rule instead of a suggestion.
 */
class LoginPinController extends Controller
{
    public function __construct(private readonly EventPublisher $events) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // digits: true rejects "0123" typed with a leading + or spaces, which would otherwise
            // hash fine and then never match what the numeric keypad sends at login.
            'pin' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string'],
        ], [
            'pin.digits' => 'PIN harus terdiri dari 6 angka.',
            'pin.required' => 'PIN harus terdiri dari 6 angka.',
            'password.required' => 'Kata sandi wajib diisi untuk mengubah PIN.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Kata sandi salah.'],
            ]);
        }

        if ($this->isTooPredictable($validated['pin'])) {
            throw ValidationException::withMessages([
                'pin' => ['PIN terlalu mudah ditebak. Hindari angka berurutan atau berulang.'],
            ]);
        }

        $isChange = $user->login_pin_hash !== null;

        DB::transaction(function () use ($user, $validated, $isChange, $request): void {
            $user->forceFill([
                'login_pin_hash' => Hash::make($validated['pin']),
                'login_pin_failures' => 0,
                'login_pin_locked_until' => null,
            ])->save();

            // Every session, on every device, including the one making this call. See the class
            // docblock. Push registrations are NOT touched: they belong to the device, and the
            // same person is about to sign back in on it.
            $user->tokens()->delete();

            AuditLog::query()->create([
                'actor_id' => $user->id,
                'actor_role' => $user->role->value,
                'action' => $isChange ? 'auth.login_pin.changed' : 'auth.login_pin.created',
                'subject_type' => $user::class,
                'subject_id' => $user->id,
                'before_json' => null,
                'after_json' => ['sessions_revoked' => true],
                'ip' => $request->ip(),
                'device_id' => null,
            ]);

            // Lands on the user's OTHER devices as a push (the actor is excluded): if this was not
            // them, they find out now rather than when their app silently signs out.
            $this->events->publish(
                'LoginPinChanged',
                $isChange ? 'PIN masuk diubah' : 'PIN masuk dibuat',
                'Semua sesi dikeluarkan. Masuk kembali dengan PIN baru Anda.',
                ["user.{$user->id}"],
                [$user->id],
            );
        });

        return response()->json([
            'data' => [
                'has_login_pin' => true,
                // Tells the client explicitly that its own token is now dead, so it shows the
                // sign-out dialog instead of discovering a 401 on the next screen.
                'sessions_revoked' => true,
            ],
        ]);
    }

    /**
     * Removing the PIN reopens the password route. It requires the password for the same reason
     * creating one does: this is the one action that changes WHICH credential opens the account.
     */
    public function destroy(Request $request): Response
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Kata sandi wajib diisi untuk menghapus PIN.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Kata sandi salah.'],
            ]);
        }

        if ($user->login_pin_hash === null) {
            return response()->noContent();
        }

        DB::transaction(function () use ($user, $request): void {
            $user->forceFill([
                'login_pin_hash' => null,
                'login_pin_failures' => 0,
                'login_pin_locked_until' => null,
            ])->save();

            AuditLog::query()->create([
                'actor_id' => $user->id,
                'actor_role' => $user->role->value,
                'action' => 'auth.login_pin.removed',
                'subject_type' => $user::class,
                'subject_id' => $user->id,
                'before_json' => null,
                'after_json' => null,
                'ip' => $request->ip(),
                'device_id' => null,
            ]);
        });

        return response()->noContent();
    }

    /**
     * A 6-digit space is only a million wide, and in practice users pick from a few hundred of
     * them. Rejecting the obvious ones costs nothing and removes the entries an attacker would
     * try first — this is a complement to the lockout in AuthController, not a substitute.
     */
    private function isTooPredictable(string $pin): bool
    {
        if (preg_match('/^(\d)\1{5}$/', $pin)) {
            return true; // 000000, 111111, ...
        }

        $ascending = '0123456789012345';
        $descending = strrev('0123456789');

        return str_contains($ascending, $pin) || str_contains($descending, $pin);
    }
}
