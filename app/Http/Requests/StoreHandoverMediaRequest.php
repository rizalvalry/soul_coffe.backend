<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /media/handover` — the photo a rider takes as the cups change hands.
 *
 * Uploaded BEFORE the delivery is completed, exactly as a refill's evidence photo is uploaded
 * before the request is created (R3/E4). That is not a stylistic choice: multipart carries one
 * streamed file per request in this client (see the mobile `uploadFileWithStatus` docblock for
 * why React Native's own FormData is not used), and a delivery may carry both a photo and a
 * signature. Pre-uploading the required one keeps each request to a single file and leaves the
 * optional signature as the file part of the delivery itself.
 *
 * Freshness and reuse (E6's rule, applied to handovers) need a timestamp comparison and a
 * database lookup, so they live in MediaService.
 */
class StoreHandoverMediaRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png', 'max:8192'],
            'taken_at' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Foto serah terima wajib diambil langsung dari kamera',
            'file.mimes' => 'Foto serah terima wajib diambil langsung dari kamera',
            'file.max' => 'Foto serah terima terlalu besar, ambil ulang dari kamera',
            'taken_at.required' => 'Foto serah terima wajib diambil langsung dari kamera',
            'taken_at.date' => 'Foto serah terima wajib diambil langsung dari kamera',
        ];
    }
}
