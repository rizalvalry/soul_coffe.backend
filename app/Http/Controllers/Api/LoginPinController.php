<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The user's own login PIN (docs/04 §Auth).
 *
 * Setting or clearing a PIN requires the account password even though the caller already holds a
 * valid token. A token can be lifted from an unlocked phone; the password cannot. Without that
 * re-check, anyone who picked up a signed-in device could quietly mint themselves a 6-digit
 * credential that survives a remote token revocation.
 */
class LoginPinController extends Controller
{
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

        $user->forceFill([
            'login_pin_hash' => Hash::make($validated['pin']),
            'login_pin_failures' => 0,
            'login_pin_locked_until' => null,
        ])->save();

        return response()->json(['data' => ['has_login_pin' => true]]);
    }

    public function destroy(Request $request): Response
    {
        $request->user()->forceFill([
            'login_pin_hash' => null,
            'login_pin_failures' => 0,
            'login_pin_locked_until' => null,
        ])->save();

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
