<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B23 - the first writer of `service_media`
 * (App\Actions\Admin\Service\AdminGetServiceAction's docblock: "nothing in
 * this codebase writes to it yet"). Stores the file on Laravel's own
 * `public` disk (storage/app/public, served via `php artisan storage:link`
 * at /storage/...) under a server-generated, non-guessable path - never a
 * client-controlled file name, never a base64 DB blob, never a third-party
 * media service.
 *
 * Write-then-cleanup ordering: the file is written to disk FIRST; the
 * `service_media` row is only inserted after that succeeds, and if the
 * DB write then fails the just-written file is deleted again - so a
 * Service can never end up pointing at a DB row with no backing file, nor
 * can an orphaned file survive a failed request. Deactivating existing
 * media (setActive) never deletes the physical file - a conservative,
 * always-safe default consistent with "old asset cleanup only when safe"
 * (BLUE V1 catalog spec section 11/35.H); the file simply becomes
 * unreachable from any active, customer-visible listing.
 */
final class AdminServiceMediaAction
{
    use BuildsCartResult;

    private const DISK = 'public';

    public function upload(Request $request, User $actor, string $serviceUuid, UploadedFile $file, string $altText, ?string $caption, bool $isPrimary, int $displayOrder): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        $service = DB::table('services')->where('id', $serviceIdBinary)->first(['id']);

        if ($service === null) {
            return $this->notFound('Service not found.');
        }

        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $storageKey = 'service-media/'.$serviceUuid.'/'.UuidBinary::generate().'.'.$extension;

        $stored = Storage::disk(self::DISK)->putFileAs(
            dirname($storageKey),
            $file,
            basename($storageKey),
        );

        if ($stored === false) {
            return $this->unprocessable('The image could not be stored. Please try again.');
        }

        try {
            [$widthPixels, $heightPixels] = @getimagesize($file->getRealPath()) ?: [null, null];

            return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor, $file, $altText, $caption, $isPrimary, $displayOrder, $storageKey, $widthPixels, $heightPixels): array {
                if ($isPrimary) {
                    DB::table('service_media')->where('service_id', $serviceIdBinary)->where('is_primary', 1)->update(['is_primary' => 0, 'updated_at' => now()]);
                }

                $mediaUuid = UuidBinary::generate();
                $now = now();

                DB::table('service_media')->insert([
                    'id' => UuidBinary::toBinary($mediaUuid),
                    'service_id' => $serviceIdBinary,
                    'storage_key' => $storageKey,
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'original_file_name' => $file->getClientOriginalName(),
                    'alt_text' => $altText,
                    'caption' => $caption,
                    'file_size_bytes' => $file->getSize(),
                    'width_pixels' => $widthPixels,
                    'height_pixels' => $heightPixels,
                    'is_primary' => $isPrimary ? 1 : 0,
                    'display_order' => $displayOrder,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                AdminAuditLogger::record(
                    $request,
                    $actor,
                    'SERVICE_MEDIA_UPLOADED',
                    'SERVICE',
                    $serviceUuid,
                    ['media_uuid' => $mediaUuid, 'is_primary' => $isPrimary],
                );

                $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

                return $this->ok(201, 'Image uploaded successfully.', ['service' => AdminServicePresenter::detail($updated)]);
            });
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($storageKey);

            throw $exception;
        }
    }

    public function setActive(Request $request, User $actor, string $mediaUuid, bool $isActive): array
    {
        try {
            $mediaIdBinary = UuidBinary::toBinary($mediaUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Image not found.');
        }

        return DB::transaction(function () use ($request, $mediaUuid, $mediaIdBinary, $actor, $isActive): array {
            $media = DB::table('service_media')->where('id', $mediaIdBinary)->lockForUpdate()->first(['id', 'service_id', 'is_active', 'is_primary']);

            if ($media === null) {
                return $this->notFound('Image not found.');
            }

            if ((bool) $media->is_active === $isActive) {
                $updated = AdminServicePresenter::loadForDetail($media->service_id);

                return $this->ok(200, $isActive ? 'Image is already active.' : 'Image is already inactive.', ['service' => AdminServicePresenter::detail($updated)]);
            }

            // Deactivating the primary image also clears the primary flag -
            // an inactive row can never remain the generated primary_marker
            // per the schema's own CHECK, and a Service should never be left
            // silently pointing at a hidden "primary" image.
            DB::table('service_media')->where('id', $mediaIdBinary)->update([
                'is_active' => $isActive ? 1 : 0,
                'is_primary' => $isActive ? $media->is_primary : 0,
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                $isActive ? 'SERVICE_MEDIA_ACTIVATED' : 'SERVICE_MEDIA_DEACTIVATED',
                'SERVICE',
                UuidBinary::toString($media->service_id),
                ['media_uuid' => $mediaUuid],
            );

            $updated = AdminServicePresenter::loadForDetail($media->service_id);

            return $this->ok(200, $isActive ? 'Image activated successfully.' : 'Image deactivated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
