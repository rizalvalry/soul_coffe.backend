<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /sales` — one transaction from a cart.
 *
 * Kept to the fields a street vendor can actually fill: which cups, how many, and how it was
 * paid. Everything else the row needs (cart, location, price, time) is derived server-side,
 * because asking someone with a queue in front of them to type a price is how a POS goes unused.
 *
 * GPS is nullable and `gps_unavailable` exists for the same reason it does on refill requests
 * (E10): a lost signal must never block the transaction, it just means the area analysis has one
 * fewer precise point.
 */
class StoreSaleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            // Upper bound per line is a typo guard, not the suspect rule — 999 cups of one
            // product in one transaction is a slipped finger, not a busy morning.
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:999'],

            'payment_method' => ['sometimes', Rule::in(['cash', 'qris', 'transfer'])],

            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_unavailable' => ['sometimes', 'boolean'],

            'note' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Pilih dulu cups yang terjual.',
            'lines.min' => 'Pilih dulu cups yang terjual.',
        ];
    }
}
