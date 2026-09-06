<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /auth/pin-reset-requests` (docs/04 §Auth).
 *
 * All three fields are required. The email is how the Administrator reaches the person once the
 * new password exists — the app has no other out-of-band channel — and the password is the
 * requester's best available proof of identity, recorded only as matched/unmatched.
 */
class StorePinResetRequest extends FormRequest
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
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'Nomor HP wajib diisi.',
            'email.required' => 'Alamat email wajib diisi.',
            'email.email' => 'Format alamat email tidak valid.',
            'password.required' => 'Kata sandi wajib diisi.',
        ];
    }
}
