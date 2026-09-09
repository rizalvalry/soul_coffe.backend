<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Shrinks an oversized evidence photo before it is written to storage.
 *
 * The mobile client already resizes before uploading, so in normal operation this does nothing.
 * It exists for the cases the client cannot cover: an older APK still in someone's hands, a
 * future client, or any direct API caller. Storage on this hosting is finite and every evidence
 * photo is kept, so a single un-resized handset photo is worth more than a hundred sensible ones.
 *
 * Deliberately narrow:
 *
 * - JPEG only. PNG is accepted by the upload rules and is left untouched, because re-encoding it
 *   would change the mime the API reports, and because the one PNG this system sends on purpose
 *   is the 1x1 pin-fallback placeholder whose exact bytes R13's uniqueness check depends on.
 * - Signatures are never passed through here for the same reason.
 * - A file already within budget is returned untouched, so a photo the client already shrank is
 *   not compressed a second time and degraded for nothing.
 */
final class ImageCompressor
{
    /** Matches the mobile client's own target. */
    private const TARGET_BYTES = 1_048_576;

    /** Long edge, in pixels. */
    private const MAX_EDGE = 1600;

    /** Tried in order; the last is accepted whatever it weighs. */
    private const QUALITY_STEPS = [80, 65, 50, 40];

    /**
     * Returns the path of the file that should be stored — either a newly written temporary file,
     * or the upload's own path when no work was needed.
     *
     * Never throws on a file it cannot process: an evidence photo that reaches storage slightly
     * too large is a far better outcome than a staff member unable to file a request at all.
     */
    public static function shrinkJpeg(UploadedFile $file): string
    {
        $source = $file->getRealPath();

        if ($source === false || ! extension_loaded('gd')) {
            return (string) $source;
        }

        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $source;
        }

        $info = @getimagesize($source);

        if ($info === false) {
            return $source;
        }

        [$width, $height] = $info;
        $withinSize = filesize($source) <= self::TARGET_BYTES;
        $withinBounds = max($width, $height) <= self::MAX_EDGE;

        if ($withinSize && $withinBounds) {
            return $source;
        }

        $image = @imagecreatefromjpeg($source);

        if ($image === false) {
            return $source;
        }

        // Scale the LONG edge, whichever it is; scaling by width alone would leave a portrait
        // photo's height far above the cap.
        if (max($width, $height) > self::MAX_EDGE) {
            $scale = self::MAX_EDGE / max($width, $height);
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));

            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        $target = tempnam(sys_get_temp_dir(), 'evidence_').'.jpg';

        foreach (self::QUALITY_STEPS as $quality) {
            if (! @imagejpeg($image, $target, $quality)) {
                imagedestroy($image);

                return $source;
            }

            clearstatcache(true, $target);

            if (filesize($target) <= self::TARGET_BYTES) {
                break;
            }
        }

        imagedestroy($image);

        // A "shrink" that produced something larger is not a shrink.
        if (! is_file($target) || filesize($target) >= filesize($source)) {
            @unlink($target);

            return $source;
        }

        return $target;
    }
}
