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
 * BLUE V1 Phase B23 - moves an existing Service to a different Category.
 * Deliberately its own Action (never folded into AdminUpdateServiceMetadataAction)
 * because re-categorizing changes which Category listing the Service
 * appears under - a structural move, not a display-metadata edit - and
 * deserves its own explicit audit trail entry showing the old and new
 * Category. Never touches any historical Booking Item - `booking_items.
 * service_id` is a foreign key to `services`, not `service_categories`, so
 * a historical Booking's Service reference is completely unaffected by
 * which Category that Service currently belongs to.
 */
final class AdminChangeServiceCategoryAction
{
    use BuildsCartResult;

    public function handle(Request $request, string $serviceUuid, User $actor, int $newCategoryId): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor, $newCategoryId): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first();

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            $newCategory = DB::table('service_categories')->where('id', $newCategoryId)->first(['id', 'name']);

            if ($newCategory === null) {
                return $this->unprocessable('The given data was invalid.', ['category_id' => ['This category does not exist.']]);
            }

            if ((int) $service->category_id === $newCategoryId) {
                return $this->ok(200, 'Service is already in this category.', ['service' => AdminServicePresenter::detail(AdminServicePresenter::loadForDetail($serviceIdBinary))]);
            }

            if (DB::table('services')->where('category_id', $newCategoryId)->where('name', $service->name)->exists()) {
                return $this->conflict('A service with this name already exists in the target category.');
            }

            $previousCategoryId = (int) $service->category_id;

            DB::table('services')->where('id', $serviceIdBinary)->update([
                'category_id' => $newCategoryId,
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CATEGORY_CHANGED',
                'SERVICE',
                $serviceUuid,
                ['category_id' => $newCategoryId, 'category_name' => $newCategory->name],
                ['category_id' => $previousCategoryId],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(200, 'Service category changed successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
