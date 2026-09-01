<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Evidence photos and signatures (docs/02 R3, R13, E6; docs/04 `POST /media/evidence`
 * and `POST /refills/{id}/deliver`).
 *
 * File shape (mime, size) is a boundary concern and is validated by the owning
 * FormRequest (StoreEvidenceMediaRequest / DeliverRefillRequestRequest) before
 * either method here is ever called — this class owns only the rules that need
 * a timestamp comparison or a database lookup, which validation rules can't do.
 */
class MediaService
{
    /**
     * E6: reject a photo whose declared capture time is stale, and dedupe by
     * content hash within a rolling window. Camera-only capture (R3) is
     * enforced by the API shape itself: there is no gallery-picker field to
     * misuse, only a direct multipart upload.
     */
    public function storeEvidence(UploadedFile $file, Carbon $takenAt, User $uploader): Media
    {
        $maxAgeMinutes = (int) config('soul.evidence_max_age_minutes', 15);

        // A small forward tolerance absorbs device/server clock drift without
        // opening the door to a stale photo — R16 keeps the server clock
        // authoritative, this just avoids punishing a few seconds of skew.
        if ($takenAt->lt(now()->subMinutes($maxAgeMinutes)) || $takenAt->gt(now()->addMinutes(1))) {
            throw ValidationException::withMessages([
                'taken_at' => ['Foto bukti wajib diambil langsung dari kamera'],
            ]);
        }

        $hash = hash_file('sha256', $file->getRealPath());
        $dedupeDays = (int) config('soul.evidence_dedupe_days', 7);

        $isDuplicate = Media::query()
            ->where('kind', 'evidence')
            ->where('sha256', $hash)
            ->where('created_at', '>=', now()->subDays($dedupeDays))
            ->exists();

        if ($isDuplicate) {
            throw ValidationException::withMessages([
                'file' => ['Foto bukti wajib diambil langsung dari kamera'],
            ]);
        }

        $path = $file->store('evidence', 'public');

        return Media::create([
            'kind' => 'evidence',
            'path' => $path,
            'mime' => $file->getMimeType(),
            'bytes' => $file->getSize(),
            'sha256' => $hash,
            'phash' => null,
            'exif_taken_at' => $takenAt,
            'uploaded_by' => $uploader->id,
        ]);
    }

    /**
     * R13: a genuine signature's sha256 may never be reused, with no time
     * window at all.
     *
     * $enforceUniqueness is false only for the pin_fallback placeholder image
     * (see RefillRequestStateMachine::deliver()): the mobile client sends a
     * fixed 1x1 PNG for every PIN-fallback delivery because there is no real
     * signature to capture, so that exact file's hash is expected to repeat.
     * Applying R13 to it would make the SECOND pin_fallback delivery ever
     * performed fail forever — exactly the deadlock E7 exists to prevent.
     */
    public function storeSignature(UploadedFile $file, User $uploader, bool $enforceUniqueness = true): Media
    {
        $hash = hash_file('sha256', $file->getRealPath());

        if ($enforceUniqueness) {
            $isReused = Media::query()->where('kind', 'signature')->where('sha256', $hash)->exists();

            if ($isReused) {
                throw ValidationException::withMessages([
                    'signature' => ['Tanda tangan tidak valid, mohon tanda tangan ulang.'],
                ]);
            }
        }

        $path = $file->store('signatures', 'public');

        return Media::create([
            'kind' => 'signature',
            'path' => $path,
            'mime' => $file->getMimeType(),
            'bytes' => $file->getSize(),
            'sha256' => $hash,
            'phash' => null,
            'exif_taken_at' => null,
            'uploaded_by' => $uploader->id,
        ]);
    }
}
