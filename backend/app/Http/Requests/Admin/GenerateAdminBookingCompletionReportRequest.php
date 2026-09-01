<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Service Completion Report generation input - every field is optional
 * except the route's own {booking} parameter. Mirrors
 * App\Http\Requests\Admin\UploadAdminServiceMediaRequest's photo validation
 * exactly (`image` + a fixed safe mime allowlist rejects anything that
 * isn't a genuine, decodable raster image - SVG, HTML, or a PDF-as-image
 * never pass `image`'s getimagesize() check), extended with an `array`/
 * `max:8` count cap per side (BLUE V1 Service Completion Report spec
 * section 7). 8MB per file is a generous but bounded ceiling; every
 * accepted photo is still re-encoded and downsized by
 * App\Support\Admin\Reports\AdminBookingCompletionReportPhotoProcessor
 * before it ever reaches the PDF.
 */
class GenerateAdminBookingCompletionReportRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'completion_note' => ['nullable', 'string', 'max:2000'],
            'before_photos' => ['nullable', 'array', 'max:8'],
            'before_photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'after_photos' => ['nullable', 'array', 'max:8'],
            'after_photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }
}
