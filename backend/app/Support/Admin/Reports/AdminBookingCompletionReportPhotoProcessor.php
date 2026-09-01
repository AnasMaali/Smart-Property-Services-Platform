<?php

namespace App\Support\Admin\Reports;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Service Completion Report - turns one Admin-uploaded Before/After photo
 * into an inline `data:image/jpeg;base64,...` URI ready to embed straight
 * into the report Blade view, and nothing else. This is the ONLY place a
 * photo's bytes are ever read: the caller (App\Actions\Admin\Booking\
 * GenerateAdminBookingCompletionReportAction) never calls store()/storeAs()/
 * move()/Storage::put() on the uploaded file, and this class never writes
 * one either - `$file->getRealPath()` still points at PHP's own request-
 * scoped temporary upload, which PHP deletes automatically once the request
 * ends. No image byte ever reaches persistent storage.
 *
 * `imagecreatefromstring()` auto-detects JPEG/PNG/WEBP (the three formats
 * App\Http\Requests\Admin\GenerateAdminBookingCompletionReportRequest
 * accepts) from the raw bytes - one decode path for all three, no per-mime
 * branching. Every output is re-encoded as JPEG: this keeps the embedded
 * report photo format uniform regardless of what the Admin uploaded (dompdf
 * embeds it identically either way), and every PNG/WEBP source with an
 * alpha channel is deliberately flattened onto a white background first -
 * JPEG has no alpha channel, and GD would otherwise render transparent
 * pixels as black.
 *
 * Requires the `gd` PHP extension (App\Support\Admin\Reports\AdminReportPdf
 * itself requires it too - dompdf cannot embed ANY raster image, including
 * a plain opaque PNG, without GD or Imagick present. This is a hard runtime
 * requirement, not an optional resize/compress enhancement).
 */
final class AdminBookingCompletionReportPhotoProcessor
{
    /**
     * Report-oriented longest-side cap (BLUE V1 Service Completion Report
     * spec section 8: "around 1600-2000 px"). A photo already smaller than
     * this is still re-canvased (never returned byte-for-byte) so its alpha
     * channel, if any, is always flattened the same way.
     */
    private const MAX_DIMENSION = 1800;

    private const JPEG_QUALITY = 82;

    public static function toDataUri(UploadedFile $file): string
    {
        $raw = file_get_contents($file->getRealPath());

        if ($raw === false || $raw === '') {
            throw new RuntimeException('Uploaded photo could not be read.');
        }

        $source = @imagecreatefromstring($raw);

        if ($source === false) {
            throw new RuntimeException('Uploaded photo is not a decodable image.');
        }

        try {
            $oriented = self::applyExifOrientation($source, $file, $raw);
            $flattened = self::flattenOntoWhiteCanvas($oriented);

            ob_start();
            imagejpeg($flattened, null, self::JPEG_QUALITY);
            $jpegBytes = ob_get_clean();
        } finally {
            imagedestroy($source);

            if (isset($oriented) && $oriented !== $source) {
                imagedestroy($oriented);
            }

            if (isset($flattened) && $flattened !== ($oriented ?? $source)) {
                imagedestroy($flattened);
            }
        }

        if ($jpegBytes === false || $jpegBytes === '') {
            throw new RuntimeException('Uploaded photo could not be re-encoded.');
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpegBytes);
    }

    /**
     * JPEG-only (the `exif` extension only reliably reads EXIF from
     * JPEG/TIFF streams) - a PNG/WEBP source is returned unchanged. Only
     * the four orientation values a real camera/phone actually writes
     * (3/6/8 - the rest are mirrored variants no mainstream camera
     * produces) are corrected; anything else is left as decoded.
     */
    private static function applyExifOrientation(\GdImage $image, UploadedFile $file, string $raw): \GdImage
    {
        if ($file->getMimeType() !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $raw);
        rewind($stream);
        $exif = @exif_read_data($stream, null, true);
        fclose($stream);

        $orientation = $exif['IFD0']['Orientation'] ?? null;

        return match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }

    /**
     * Resizes down to MAX_DIMENSION (never up) and flattens any alpha
     * channel onto solid white - the one canvas operation every photo goes
     * through, whether or not it needed resizing, so transparency is
     * always handled identically.
     */
    private static function flattenOntoWhiteCanvas(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longestSide = max($width, $height);

        $scale = $longestSide > self::MAX_DIMENSION ? (self::MAX_DIMENSION / $longestSide) : 1.0;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }
}
