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
 * BLUE V1 Phase B23-ext - sets a Service's catalog display/policy
 * metadata: `is_featured` (customer-facing promotion badge),
 * `estimated_duration_minutes`, and the `min_quantity`/`max_quantity` Cart
 * bound (see App\Support\Catalog\ServiceQuantityPolicy). None of this is a
 * pricing authority and none of it is read by the canonical pricing
 * calculation engine - it is additive catalog metadata exactly like
 * `services.original_price` already is.
 *
 * `is_featured` deliberately stays a plain column on `services`, not a
 * `service_capabilities` row: every existing capability
 * (CART_ELIGIBLE/SUBSCRIPTION/QUOTE_ONLY/EMERGENCY/REQUIRES_SITE_VISIT)
 * gates real downstream behavior and is presented read-only to Admin for
 * exactly that reason (see AdminGetServiceAction's docblock) - "featured"
 * is pure marketing metadata with zero behavioral effect, and mixing it
 * into that same read-only, behavior-gating vocabulary would blur a
 * boundary this codebase otherwise keeps sharp.
 */
final class AdminSetServiceCatalogPolicyAction
{
    use BuildsCartResult;

    /**
     * @param  array{is_featured: bool, estimated_duration_minutes: ?int, min_quantity: int, max_quantity: int}  $data
     */
    public function handle(Request $request, User $actor, string $serviceUuid, array $data): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        if ($data['min_quantity'] > $data['max_quantity']) {
            return $this->unprocessable('The given data was invalid.', [
                'max_quantity' => ['max_quantity must be greater than or equal to min_quantity.'],
            ]);
        }

        return DB::transaction(function () use ($request, $actor, $serviceUuid, $serviceIdBinary, $data): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first([
                'id', 'is_featured', 'estimated_duration_minutes', 'min_quantity', 'max_quantity',
            ]);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            DB::table('services')->where('id', $serviceIdBinary)->update([
                'is_featured' => $data['is_featured'] ? 1 : 0,
                'estimated_duration_minutes' => $data['estimated_duration_minutes'],
                'min_quantity' => $data['min_quantity'],
                'max_quantity' => $data['max_quantity'],
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CATALOG_POLICY_CHANGED',
                'SERVICE',
                $serviceUuid,
                $data,
                [
                    'is_featured' => (bool) $service->is_featured,
                    'estimated_duration_minutes' => $service->estimated_duration_minutes === null ? null : (int) $service->estimated_duration_minutes,
                    'min_quantity' => (int) $service->min_quantity,
                    'max_quantity' => (int) $service->max_quantity,
                ],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(200, 'Catalog policy updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
