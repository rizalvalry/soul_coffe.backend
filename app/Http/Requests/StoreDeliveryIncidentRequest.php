<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /refills/{id}/incident` — multipart (RIDER).
 *
 * `lines` arrives as a JSON string for the same reason the delivery endpoint's does: multipart
 * has no encoding for an array of objects that the mobile client can rely on.
 *
 * The photo is required and there is no way to send this without one. That is the whole point of
 * the report — a claim that cups were destroyed, with no picture, is indistinguishable from cups
 * that were never delivered.
 */
class StoreDeliveryIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (is_string($lines)) {
            $decoded = json_decode($lines, true);
            $this->merge(['lines' => is_array($decoded) ? $decoded : []]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],

            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
            'photo_taken_at' => ['required', 'date'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer', 'exists:refill_request_lines,id'],
            // The upper bound that matters — damaged must not exceed what was sent — needs the
            // request's own lines, so it lives in DeliveryIncidentService.
            'lines.*.qty_damaged' => ['required', 'integer', 'min:1', 'max:999'],

            'note' => ['nullable', 'string', 'max:500'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => 'Foto insiden wajib diambil langsung dari kamera.',
            'lines.required' => 'Isi dulu jumlah cups yang rusak.',
        ];
    }
}
