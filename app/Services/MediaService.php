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

        $existing = Media::query()
            ->where('kind', 'evidence')
            ->where('sha256', $hash)
            ->where('created_at', '>=', now()->subDays($dedupeDays))
            ->latest('id')
            ->first();

        if ($existing !== null) {
            // A retried upload is not a reused photo. The mobile client retries the multipart
            // POST when the connection dies before the response arrives (see its
            // `uploadFileWithStatus()`), and the first attempt may well have been stored — so
            // the retry arrives with identical bytes seconds later. Rejecting it would strand
            // the staff member on a request they can never submit, which is the exact failure
            // R3 exists to prevent, inverted.
            //
            // The anti-reuse rule still holds: the row is returned only when the SAME uploader
            // sent it, within a short window, and it has not yet been attached to a refill
            // request. Anything else — another user's photo, yesterday's photo, or one already
            // spent on a request — is still a reuse and is still refused.
            $isSameUploaderRetry = $existing->uploaded_by === $uploader->id
                && $existing->created_at !== null
                && $existing->created_at->gte(now()->subMinutes((int) config('soul.evidence_retry_window_minutes', 10)))
                && ! $existing->refillRequestsAsEvidence()->exists();

            if (! $isSameUploaderRetry) {
                throw ValidationException::withMessages([
                    'file' => ['Foto bukti wajib diambil langsung dari kamera'],
                ]);
            }

            return $existing;
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
     * The photo taken as the cups change hands (2026-09-10).
     *
     * Held to the same standard as a refill's evidence photo, and for the same reason: a picture
     * that could have been taken yesterday, or copied from another delivery, proves nothing. So
     * it must be fresh, and its bytes must not already exist as a handover photo — with the one
     * exception that a retried upload of the same bytes by the same rider, not yet attached to
     * any delivery, is a retry rather than a reuse.
     *
     * Deliberately its own `kind` rather than reusing 'evidence': the two answer different
     * questions ("the cart was empty" against "the cups arrived"), and sharing a dedupe pool
     * would let one be refused because of the other.
     */
    public function storeHandoverPhoto(UploadedFile $file, Carbon $takenAt, User $uploader): Media
    {
        return $this->storeCameraCapture(
            file: $file,
            takenAt: $takenAt,
            uploader: $uploader,
            kind: 'handover',
            directory: 'handover',
            staleField: 'handover_photo_taken_at',
            reuseField: 'handover_photo',
            message: 'Foto serah terima wajib diambil langsung dari kamera',
            isAlreadySpent: fn (Media $media): bool => $media->refillRequestsAsHandoverPhoto()->exists(),
        );
    }

    /**
     * The photo a rider takes when cups are damaged on the way (2026-09-10).
     *
     * Same standard again, and here the standard is the whole point: this photo is the only
     * reason anyone downstream can tell a genuine accident from cups that were simply never
     * delivered. Finance decides what happens next by looking at it.
     */
    public function storeIncidentPhoto(UploadedFile $file, Carbon $takenAt, User $uploader): Media
    {
        return $this->storeCameraCapture(
            file: $file,
            takenAt: $takenAt,
            uploader: $uploader,
            kind: 'incident',
            directory: 'incidents',
            staleField: 'photo_taken_at',
            reuseField: 'photo',
            message: 'Foto insiden wajib diambil langsung dari kamera',
            isAlreadySpent: fn (Media $media): bool => $media->deliveryIncidents()->exists(),
        );
    }

    /**
     * The rule shared by every "taken right now, on the spot" photo: fresh, and not a picture
     * that has already been used for something else.
     *
     * One implementation rather than three near-identical copies, because the interesting part —
     * the retry exception — is subtle enough that a divergent copy would be a bug waiting for a
     * bad connection. Each caller supplies only what differs: which `kind` it dedupes against,
     * which field name the error belongs to, and how to tell whether a matching row has already
     * been spent.
     *
     * @param  callable(Media): bool  $isAlreadySpent
     */
    private function storeCameraCapture(
        UploadedFile $file,
        Carbon $takenAt,
        User $uploader,
        string $kind,
        string $directory,
        string $staleField,
        string $reuseField,
        string $message,
        callable $isAlreadySpent,
    ): Media {
        $maxAgeMinutes = (int) config('soul.evidence_max_age_minutes', 15);

        // A minute of forward tolerance absorbs clock skew; the server clock stays authoritative
        // (R16).
        if ($takenAt->lt(now()->subMinutes($maxAgeMinutes)) || $takenAt->gt(now()->addMinutes(1))) {
            throw ValidationException::withMessages([$staleField => [$message]]);
        }

        $hash = hash_file('sha256', $file->getRealPath());

        $existing = Media::query()
            ->where('kind', $kind)
            ->where('sha256', $hash)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            // A retried upload is not a reused photo — see storeEvidence() for the failure this
            // exception exists to prevent.
            $isSameUploaderRetry = $existing->uploaded_by === $uploader->id
                && $existing->created_at !== null
                && $existing->created_at->gte(now()->subMinutes((int) config('soul.evidence_retry_window_minutes', 10)))
                && ! $isAlreadySpent($existing);

            if (! $isSameUploaderRetry) {
                throw ValidationException::withMessages([$reuseField => [$message]]);
            }

            return $existing;
        }

        $path = $file->store($directory, 'public');

        return Media::create([
            'kind' => $kind,
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
