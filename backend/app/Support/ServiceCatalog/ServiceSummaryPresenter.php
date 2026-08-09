<?php

namespace App\Support\ServiceCatalog;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * The one safe, Flutter-facing "service" summary shape (uuid, slug, name,
 * primary_image) embedded in every cart/checkout item line. Extracted from
 * the Phase 4 CartPresenter so Checkout (Phase 5) reuses it instead of a
 * second copy.
 */
final class ServiceSummaryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(string $serviceIdBinary): array
    {
        $service = DB::table('services')->where('id', $serviceIdBinary)->first(['id', 'slug', 'name']);

        $primaryImage = DB::table('service_media')
            ->where('service_id', $serviceIdBinary)
            ->where('is_primary', 1)
            ->where('is_active', 1)
            ->first(['id', 'storage_key', 'mime_type', 'alt_text']);

        return [
            'uuid' => UuidBinary::toString($service->id),
            'slug' => $service->slug,
            'name' => $service->name,
            'primary_image' => $primaryImage === null ? null : [
                'uuid' => UuidBinary::toString($primaryImage->id),
                'storage_key' => $primaryImage->storage_key,
                'mime_type' => $primaryImage->mime_type,
                'alt_text' => $primaryImage->alt_text,
            ],
        ];
    }
}
