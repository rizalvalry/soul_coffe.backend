<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'url' => Storage::disk('public')->url($this->path),
            'mime' => $this->mime,
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
