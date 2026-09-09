<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEvidenceMediaRequest;
use App\Http\Requests\StoreHandoverMediaRequest;
use App\Http\Resources\MediaResource;
use App\Services\MediaService;
use Illuminate\Support\Carbon;

/**
 * Photos that are uploaded before the action they belong to.
 *
 * `POST /media/evidence` (docs/04) — a refill's evidence, so a request without evidence cannot
 * exist (R3, E4). `POST /media/handover` — the required photo of a delivery, for the same reason
 * and with the same shape.
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

    /**
     * `POST /media/handover` (RIDER). The required evidence of a delivery, uploaded just before
     * the delivery itself — see StoreHandoverMediaRequest for why it is a separate request.
     */
    public function storeHandover(StoreHandoverMediaRequest $request)
    {
        if ($request->user()->role !== Role::RIDER) {
            abort(403, 'Hanya rider yang mengunggah foto serah terima.');
        }

        $media = $this->media->storeHandoverPhoto(
            $request->file('file'),
            Carbon::parse($request->validated('taken_at')),
            $request->user(),
        );

        return (new MediaResource($media))->response()->setStatusCode(201);
    }
}
