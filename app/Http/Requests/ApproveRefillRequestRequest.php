<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /refills/{id}/approve` (FINANCE). `version` is not shown in the
 * abbreviated body sample in docs/04, but E1 explicitly requires an
 * optimistic-lock check, and a client can only supply the version it last
 * observed — so it is accepted here as optional: when present it is checked
 * (E1 the loser gets 409), when absent no version guard is applied.
 */
class ApproveRefillRequestRequest extends FormRequest
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
            'version' => ['nullable', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer', 'exists:refill_request_lines,id'],
            'lines.*.qty_approved' => ['required', 'integer', 'min:0'],
            'partial_reason' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }
}
