<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B23-ext - create/update/activate/deactivate for one
 * `service_content_sections` row (Overview / Recommended For / What's
 * Included / a free-form custom heading). `section_type_id` is a coarse,
 * seeded lookup category only (see database/phase23_catalog_model_
 * extension_migration.sql) - `title`/`body` are always Admin-authored per
 * instance, since a custom marketing headline like "Keep Your Car Running
 * Like New" is never just the type's name. Never a hard delete - see BLUE
 * V1 standing deactivate-over-delete policy already used throughout
 * Phase B23.
 */
final class AdminServiceContentSectionAction
{
    use BuildsCartResult;

    /**
     * @param  array{section_type_code: string, title: string, body: string, display_order: int}  $data
     */
    public function create(Request $request, User $actor, string $serviceUuid, array $data): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor, $data): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first(['id']);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            $sectionType = DB::table('service_content_section_types')->where('code', $data['section_type_code'])->first(['id']);

            if ($sectionType === null) {
                return $this->unprocessable('The given data was invalid.', ['section_type_code' => ['This content section type does not exist.']]);
            }

            $sectionUuid = UuidBinary::generate();
            $now = now();

            DB::table('service_content_sections')->insert([
                'id' => UuidBinary::toBinary($sectionUuid),
                'service_id' => $serviceIdBinary,
                'section_type_id' => $sectionType->id,
                'title' => $data['title'],
                'body' => $data['body'],
                'display_order' => $data['display_order'],
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CONTENT_SECTION_CREATED',
                'SERVICE',
                $serviceUuid,
                ['section_uuid' => $sectionUuid, 'section_type_code' => $data['section_type_code'], 'title' => $data['title']],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(201, 'Content section created successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    /**
     * @param  array{title: string, body: string, display_order: int}  $data
     */
    public function update(Request $request, User $actor, string $sectionUuid, array $data): array
    {
        try {
            $sectionIdBinary = UuidBinary::toBinary($sectionUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Content section not found.');
        }

        return DB::transaction(function () use ($request, $sectionUuid, $sectionIdBinary, $actor, $data): array {
            $section = DB::table('service_content_sections')->where('id', $sectionIdBinary)->lockForUpdate()->first(['id', 'service_id', 'title', 'display_order']);

            if ($section === null) {
                return $this->notFound('Content section not found.');
            }

            DB::table('service_content_sections')->where('id', $sectionIdBinary)->update([
                'title' => $data['title'],
                'body' => $data['body'],
                'display_order' => $data['display_order'],
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CONTENT_SECTION_UPDATED',
                'SERVICE',
                UuidBinary::toString($section->service_id),
                ['section_uuid' => $sectionUuid, 'title' => $data['title'], 'display_order' => $data['display_order']],
                ['title' => $section->title, 'display_order' => (int) $section->display_order],
            );

            $updated = AdminServicePresenter::loadForDetail($section->service_id);

            return $this->ok(200, 'Content section updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }

    public function setActive(Request $request, User $actor, string $sectionUuid, bool $isActive): array
    {
        try {
            $sectionIdBinary = UuidBinary::toBinary($sectionUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Content section not found.');
        }

        return DB::transaction(function () use ($request, $sectionUuid, $sectionIdBinary, $actor, $isActive): array {
            $section = DB::table('service_content_sections')->where('id', $sectionIdBinary)->lockForUpdate()->first(['id', 'service_id', 'is_active']);

            if ($section === null) {
                return $this->notFound('Content section not found.');
            }

            if ((bool) $section->is_active === $isActive) {
                $updated = AdminServicePresenter::loadForDetail($section->service_id);

                return $this->ok(200, $isActive ? 'Content section is already active.' : 'Content section is already inactive.', ['service' => AdminServicePresenter::detail($updated)]);
            }

            DB::table('service_content_sections')->where('id', $sectionIdBinary)->update(['is_active' => $isActive ? 1 : 0, 'updated_at' => now()]);

            AdminAuditLogger::record(
                $request,
                $actor,
                $isActive ? 'SERVICE_CONTENT_SECTION_ACTIVATED' : 'SERVICE_CONTENT_SECTION_DEACTIVATED',
                'SERVICE',
                UuidBinary::toString($section->service_id),
                ['section_uuid' => $sectionUuid],
            );

            $updated = AdminServicePresenter::loadForDetail($section->service_id);

            return $this->ok(200, $isActive ? 'Content section activated successfully.' : 'Content section deactivated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
