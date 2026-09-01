<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /auth/login` (docs/04 §Auth).
 *
 * Deliberately has no `role` rule: a `role` field in the body is never read anywhere in the
 * login flow, by AuthController or this class. The server alone decides the role from the
 * looked-up user row — see docs/02 §2, "the server decides the role on login".
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // unauthenticated endpoint — no prior identity to check
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
