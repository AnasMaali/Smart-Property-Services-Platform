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
 * Activating a Service makes it appear again in GET /v1/service-categories/
 * {category}/services (if its Category is also active) and reachable again
 * via GET /v1/services/{slug} - both filter on `services.is_active = 1`
 * today. Already-active is a safe idempotent no-op: no audit row is
 * written when nothing actually changes.
 */
final class AdminActivateServiceAction
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

            if ((int) $service->is_active === 1) {
                return $this->ok(200, 'Service is already active.', ['service' => AdminServicePresenter::detail(AdminServicePresenter::loadForDetail($serviceIdBinary))]);
            }

            DB::table('services')->where('id', $serviceIdBinary)->update(['is_active' => 1, 'updated_at' => now()]);

            AdminAuditLogger::record($request, $actor, 'SERVICE_ACTIVATED', 'SERVICE', $serviceUuid);

            return $this->ok(200, 'Service activated successfully.', ['service' => AdminServicePresenter::detail(AdminServicePresenter::loadForDetail($serviceIdBinary))]);
        });
    }
}
