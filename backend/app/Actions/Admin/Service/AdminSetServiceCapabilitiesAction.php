<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Pricing\ServiceCapabilities;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sets a Service's COMPLETE `service_capabilities` set (replace semantics,
 * the same "set the whole set" convention as App\Actions\Admin\Service\
 * AdminSetServiceSpecializationAction / AdminSetServicePaymentMethodsAction
 * elsewhere in this module). Unlike `service_payment_methods`, the
 * `service_capabilities` pivot has no `is_active` column of its own (its
 * PRIMARY KEY is the composite `(service_id, capability_type_id)`), so
 * "removing" a capability means deleting its pivot row, not flipping a
 * flag - rows are deleted for codes no longer requested and inserted for
 * newly-requested codes, inside one locked transaction. An empty array is
 * a valid request (clears every capability) - unlike payment methods,
 * nothing about "zero capabilities" is an invalid domain state.
 *
 * CART_ELIGIBLE and SUBSCRIPTION are the only two capability codes with
 * real runtime behavior today (App\Support\Pricing\ServiceCapabilities::
 * has(), checked by App\Actions\Cart\AddCartItemAction/
 * UpdateCartItemAction and App\Actions\Contract\RequestContractAction/
 * App\Actions\Admin\Contract\AdminApproveContractAction respectively).
 * Both are FORWARD-LOOKING eligibility configuration only: removing one
 * blocks future Cart adds/updates or future Contract requests/approvals,
 * but never touches an already-placed CartItem, an already-created
 * Booking/Payment, or an already-approved/active Contract - none of those
 * carry a live dependency on `service_capabilities` (the same "safe,
 * non-cascading" precedent App\Actions\Admin\Service\
 * AdminDeactivateServiceAction already established for `services.
 * is_active`). This Action never deletes a CartItem, never rewrites a
 * checkout/payment snapshot, and never cancels/rewrites a Contract.
 * QUOTE_ONLY/EMERGENCY/REQUIRES_SITE_VISIT remain vocabulary-only - stored
 * and read, with no runtime behavior anywhere in this codebase yet.
 *
 * Idempotent: if the requested set (order-independent) already matches
 * the current set, this is a safe no-op - no writes, no audit row.
 */
final class AdminSetServiceCapabilitiesAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly ServiceCapabilities $capabilities = new ServiceCapabilities,
    ) {}

    /**
     * @param  array<int, string>  $capabilityCodes
     */
    public function handle(Request $request, User $actor, string $serviceUuid, array $capabilityCodes): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        $capabilityCodes = array_values(array_unique($capabilityCodes));

        return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor, $capabilityCodes): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first(['id']);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            $before = $this->capabilities->codesFor($serviceUuid);

            $beforeSorted = $before;
            sort($beforeSorted);
            $requestedSorted = $capabilityCodes;
            sort($requestedSorted);

            if ($requestedSorted === $beforeSorted) {
                $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

                return $this->ok(200, 'Capabilities are already up to date.', ['service' => AdminServicePresenter::detail($updated)]);
            }

            $types = $capabilityCodes === [] ? collect() : DB::table('service_capability_types')
                ->whereIn('code', $capabilityCodes)
                ->where('is_active', 1)
                ->get(['id', 'code']);

            $missing = array_diff($capabilityCodes, $types->pluck('code')->all());

            if ($missing !== []) {
                return $this->unprocessable('The given data was invalid.', [
                    'capabilities' => ['These capabilities do not exist or are not active: '.implode(', ', $missing).'.'],
                ]);
            }

            $typeIds = $types->pluck('id')->all();

            if ($typeIds === []) {
                DB::table('service_capabilities')->where('service_id', $serviceIdBinary)->delete();
            } else {
                DB::table('service_capabilities')
                    ->where('service_id', $serviceIdBinary)
                    ->whereNotIn('capability_type_id', $typeIds)
                    ->delete();
            }

            $existingTypeIds = DB::table('service_capabilities')->where('service_id', $serviceIdBinary)->pluck('capability_type_id')->all();
            $now = now();

            foreach ($typeIds as $typeId) {
                if (! in_array($typeId, $existingTypeIds, true)) {
                    DB::table('service_capabilities')->insert([
                        'service_id' => $serviceIdBinary,
                        'capability_type_id' => $typeId,
                        'created_at' => $now,
                    ]);
                }
            }

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CAPABILITIES_CHANGED',
                'SERVICE',
                $serviceUuid,
                ['capabilities' => $requestedSorted],
                ['capabilities' => $beforeSorted],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(200, 'Capabilities updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
