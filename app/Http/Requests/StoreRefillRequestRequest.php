<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /refills` (docs/04 §Flow B). Authorization (STAFF only) is enforced
 * via RefillRequestPolicy::create in the controller, not here — a FormRequest
 * that fails authorize() renders a bare 403 with no policy-specific handling,
 * and this app's 403s are meant to come from Gate::authorize().
 */
class StoreRefillRequestRequest extends FormRequest
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
        $maxQty = (int) config('soul.max_qty_per_line', 100);

        return [
            'uuid' => ['required', 'uuid'],
            'cart_id' => ['required', 'integer', 'exists:carts,id'],
            'evidence_media_id' => ['required', 'integer', Rule::exists('media', 'id')->where('kind', 'evidence')],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_unavailable' => ['sometimes', 'boolean'],
            'client_submitted_at' => ['nullable', 'date'],
            'device_id' => ['nullable', 'string', 'max:191'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty_requested' => ['required', 'integer', 'min:1', "max:{$maxQty}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxQty = (int) config('soul.max_qty_per_line', 100);

        return [
            'lines.required' => 'Pilih minimal satu produk',
            'lines.min' => 'Pilih minimal satu produk',
            'lines.*.qty_requested.min' => "Jumlah harus antara 1 dan {$maxQty} cups",
            'lines.*.qty_requested.max' => "Jumlah harus antara 1 dan {$maxQty} cups",
            'cart_id.exists' => 'Gerobak tidak ditemukan.',
            'evidence_media_id.exists' => 'Foto bukti tidak ditemukan atau tidak valid.',
        ];
    }
}
