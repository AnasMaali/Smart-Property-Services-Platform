<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * BLUE V1 Phase B23 - `image` + a fixed safe mime allowlist together reject
 * anything that isn't a genuine, decodable raster image (Laravel's `image`
 * rule inspects the actual file content via getimagesize(), not just the
 * client-supplied extension/MIME header) - never an executable, SVG (XML,
 * can carry embedded script), or arbitrary upload. 5MB is a generous but
 * bounded ceiling for a catalog photo.
 */
class UploadAdminServiceMediaRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'alt_text' => ['required', 'string', 'min:2', 'max:250'],
            'caption' => ['nullable', 'string', 'min:1', 'max:500'],
            'is_primary' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
