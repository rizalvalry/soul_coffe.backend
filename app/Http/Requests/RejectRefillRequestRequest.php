<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /refills/{id}/reject` (FINANCE). §9: reason required, >=10 chars.
 */
class RejectRefillRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan penolakan wajib diisi (min. 10 karakter)',
            'reason.min' => 'Alasan penolakan wajib diisi (min. 10 karakter)',
        ];
    }
}
