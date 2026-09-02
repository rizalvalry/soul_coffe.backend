<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /auth/login-pin` (docs/04 §Auth).
 *
 * Like LoginRequest, this carries no `role` field: the server alone decides the role from the
 * looked-up user row.
 *
 * The messages are intentionally shape-only ("6 angka"). Whether a PIN exists for this phone, or
 * whether the account is active, is never revealed here — AuthController answers all of that with
 * one generic 401, and a helpful validation message would undo it.
 */
class LoginWithPinRequest extends FormRequest
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
            'pin' => ['required', 'string', 'digits:6'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.required' => 'PIN harus terdiri dari 6 angka.',
            'pin.digits' => 'PIN harus terdiri dari 6 angka.',
        ];
    }
}
