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
 * Deactivating a Service removes it from GET /v1/service-categories/
 * {category}/services and makes GET /v1/services/{slug} 404 (both filter
 * on `services.is_active = 1`) - it only stops NEW Cart additions
 * (App\Actions\Cart\AddCartItemAction/UpdateCartItemAction both require
 * `is_active = 1` at the moment a selection is made). It does NOT remove
 * the Service from a Cart it is already in, and does NOT affect any
 * existing Booking or Contract - `booking_item_option_selections` snapshots
 * every field at booking time, and neither Booking nor Contract rows carry
 * a live dependency on `services.is_active`. Already-inactive is a safe
 * idempotent no-op: no audit row is written when nothing actually changes.
 */
final class AdminDeactivateServiceAction
{
    use BuildsCartResult;

    public function handle(Request $request, string $serviceUuid, User $actor): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first();

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            if ((int) $service->is_active === 0) {
                return $this->ok(200, 'Service is already inactive.', ['service' => AdminServicePresenter::detail(AdminServicePresenter::loadForDetail($serviceIdBinary))]);
            }

            DB::table('services')->where('id', $serviceIdBinary)->update(['is_active' => 0, 'updated_at' => now()]);

            AdminAuditLogger::record($request, $actor, 'SERVICE_DEACTIVATED', 'SERVICE', $serviceUuid);

            return $this->ok(200, 'Service deactivated successfully.', ['service' => AdminServicePresenter::detail(AdminServicePresenter::loadForDetail($serviceIdBinary))]);
        });
    }
}
