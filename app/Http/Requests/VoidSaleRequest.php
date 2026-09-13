<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /sales/{sale}/void` — undoes a sale.
 *
 * Only the reason is asked for. Who may void, and until when, is a business rule that depends on
 * the sale itself (whose it was, how long ago) rather than a static validation rule — see
 * `SaleService::void()`.
 */
class VoidSaleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
        ];
    }
}
