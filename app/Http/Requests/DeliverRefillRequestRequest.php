<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /refills/{id}/deliver` — multipart (RIDER). `lines` arrives as a JSON
 * string in the multipart body (there is no multipart array-of-objects
 * encoding the mobile client can rely on); prepareForValidation() decodes it
 * once so the rest of validation and the controller both see a plain array.
 *
 * `stroke_count >= 3` (E24) is deliberately NOT enforced here: it must be
 * skipped for `pin_fallback`, and that condition lives with the request's
 * business meaning in RefillRequestStateMachine::deliver(), not as a static
 * rule duplicated per signature_method.
 */
class DeliverRefillRequestRequest extends FormRequest
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

        $this->merge([
            'gps_unavailable' => $this->boolean('gps_unavailable'),
            'stroke_count' => (int) $this->input('stroke_count', 0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signature' => ['required', 'file', 'mimes:png', 'max:5120'],
            'signature_method' => ['required', Rule::in(['staff_signature', 'pin_fallback'])],
            'staff_pin' => ['nullable', 'string', 'required_if:signature_method,pin_fallback'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'stroke_count' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer', 'exists:refill_request_lines,id'],
            'lines.*.qty_received' => ['required', 'integer', 'min:0'],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_unavailable' => ['sometimes', 'boolean'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }
}
