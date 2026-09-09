<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /me/location` — a batch of positions from one device.
 *
 * A batch rather than a single point because the app keeps reporting while offline and flushes
 * when the connection returns; requiring one request per fix would lose exactly the trail
 * through the dead spots that is most interesting.
 *
 * Nothing here is required except the coordinates themselves. Accuracy, battery and movement are
 * hints the phone may or may not have, and a missing hint must never cost a position.
 */
class StoreLocationPingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pings' => ['required', 'array', 'min:1', 'max:100'],
            'pings.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'pings.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'pings.*.accuracy_m' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'pings.*.battery_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'pings.*.is_moving' => ['nullable', 'boolean'],
            // The device's own clock, kept beside the server's rather than trusted instead of it.
            'pings.*.captured_at' => ['nullable', 'date'],

            'device_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
