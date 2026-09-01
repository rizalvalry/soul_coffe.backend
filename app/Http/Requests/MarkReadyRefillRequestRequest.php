<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /refills/{id}/ready` (BARISTA). Whether `shortfall_reason` is
 * actually required depends on comparing qty_prepared to qty_approved per
 * line — that comparison needs the persisted request, so it is a
 * RefillRequestStateMachine guard (E9), not a static rule here.
 */
class MarkReadyRefillRequestRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer', 'exists:refill_request_lines,id'],
            'lines.*.qty_prepared' => ['required', 'integer', 'min:0'],
            'shortfall_reason' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ];
    }
}
