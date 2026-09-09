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
 * skipped for `pin_fallback` and for a delivery with no signature at all, and that condition
 * lives with the request's business meaning in RefillRequestStateMachine::deliver(), not as a
 * static rule duplicated per signature_method.
 *
 * WHAT CHANGED ON 2026-09-10: the handover PHOTO is required and the SIGNATURE is optional. It
 * used to be the other way round. A signature field with `required` on it meant a rider standing
 * in the street with a crate in one hand could not close a delivery that had plainly happened,
 * while the artefact that would actually settle a dispute — a picture of the cups being handed
 * over — was not collected at all.
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'handover_media_id.required' => 'Foto serah terima wajib diambil langsung dari kamera.',
            'handover_media_id.exists' => 'Foto serah terima tidak ditemukan atau tidak valid.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The required artefact since 2026-09-10. A photograph of the handover shows the
            // cups, the cart and the person receiving them; a finger-drawn squiggle proves only
            // that somebody drew a squiggle. See the migration that added handover_photo_id.
            //
            // Referenced by id rather than uploaded here, exactly as a refill references its
            // evidence photo: this request may also carry a signature file, and the mobile
            // client streams one file per request on purpose (see its uploadFileWithStatus
            // docblock for the cellular failure that forced that). Ownership and freshness are
            // checked in RefillRequestStateMachine::deliver().
            'handover_media_id' => ['required', 'integer', Rule::exists('media', 'id')->where('kind', 'handover')],

            // Now optional — a rider who has the staff member's signature may still record it,
            // and a rider who does not is no longer blocked from completing a delivery that
            // demonstrably happened. Absent means "no signature was taken", which is a fact the
            // row records rather than a gap to be filled with a placeholder.
            'signature' => ['nullable', 'file', 'mimes:png', 'max:5120'],
            'signature_method' => ['nullable', Rule::in(['staff_signature', 'pin_fallback'])],
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
