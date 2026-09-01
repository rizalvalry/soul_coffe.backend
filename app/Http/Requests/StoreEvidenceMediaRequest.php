<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /media/evidence` (docs/04). Camera-only capture (R3) has no separate
 * flag to validate: the API shape is a direct file upload, there is no
 * gallery-picker parameter to misuse. Recency and dedupe (E6) need a
 * timestamp comparison and a DB lookup, so they live in MediaService instead
 * of here.
 */
class StoreEvidenceMediaRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png', 'max:5120'],
            'taken_at' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Foto bukti wajib diambil langsung dari kamera',
            'file.mimes' => 'Foto bukti wajib diambil langsung dari kamera',
            'file.max' => 'Foto bukti wajib diambil langsung dari kamera',
            'taken_at.required' => 'Foto bukti wajib diambil langsung dari kamera',
            'taken_at.date' => 'Foto bukti wajib diambil langsung dari kamera',
        ];
    }
}
