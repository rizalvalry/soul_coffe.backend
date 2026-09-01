<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEvidenceMediaRequest;
use App\Http\Resources\MediaResource;
use App\Services\MediaService;
use Illuminate\Support\Carbon;

/**
 * `POST /media/evidence` (docs/04). Uploaded before the refill request is
 * created, so a request without evidence cannot exist (R3, E4).
 */
class MediaController extends Controller
{
    public function __construct(private readonly MediaService $media) {}

    public function storeEvidence(StoreEvidenceMediaRequest $request)
    {
        $media = $this->media->storeEvidence(
            $request->file('file'),
            Carbon::parse($request->validated('taken_at')),
            $request->user(),
        );

        return (new MediaResource($media))->response()->setStatusCode(201);
    }
}
