<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
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

    public function logout(Request $request): Response
    {
        // Only the token used for THIS request — logging out one device must not revoke
        // every other device the user is signed in on (BYOD, spec §14 Q8).
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
