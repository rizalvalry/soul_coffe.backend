<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKitchenRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('lines'))) {
            $decoded = json_decode($this->input('lines'), true);
            $this->merge(['lines' => is_array($decoded) ? $decoded : []]);
        }
    }

    public function rules(): array
    {
        return [
            'kitchen_id' => ['nullable', 'integer', 'exists:central_kitchens,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'reason_code' => ['required', Rule::in(['DROPPED', 'PACKAGING', 'QUALITY', 'OTHER'])],
            'note' => [Rule::requiredIf(fn (): bool => $this->input('reason_code') === 'OTHER'), 'nullable', 'string', 'max:500'],
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
            'photo_taken_at' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Ambil foto reject dulu ya.',
            'note.required' => 'Tulis alasan reject dulu ya.',
            'lines.*.product_id.distinct' => 'Produk yang sama cukup satu baris ya.',
        ];
    }
}
