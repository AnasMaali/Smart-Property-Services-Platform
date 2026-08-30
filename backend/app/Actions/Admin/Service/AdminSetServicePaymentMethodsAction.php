<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Payment\ServicePaymentPolicy;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B24 - sets a Service's COMPLETE allowed-payment-method set
 * (replace semantics, like AdminSetServiceSpecializationAction's sibling
 * "set the whole set" convention elsewhere in this module - never a
 * separate add/remove pair for a set this small). At least one active
 * method is required - a Service with zero allowed methods could never be
 * checked out at all, which is never a valid state for anything reachable
 * from the customer catalog.
 *
 * Deliberately never validates "prepayment-required + PAY_ON_SITE" as a
 * conflicting combination - there is no such combination to reject, since
 * `requires_prepayment` is only ever the COMPUTED absence of PAY_ON_SITE
 * from the allowed set (see App\Support\Payment\ServicePaymentPolicy) and
 * is never itself an input here.
 */
final class AdminSetServicePaymentMethodsAction
{
    use BuildsCartResult;

    /**
     * @param  array<int, string>  $methodCodes
     */
    public function handle(Request $request, User $actor, string $serviceUuid, array $methodCodes): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        $methodCodes = array_values(array_unique($methodCodes));

        if ($methodCodes === []) {
            return $this->unprocessable('The given data was invalid.', [
                'payment_methods' => ['At least one payment method must be allowed.'],
            ]);
        }

        return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor, $methodCodes): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first(['id']);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            $types = DB::table('payment_method_types')
                ->whereIn('code', $methodCodes)
                ->where('is_active', 1)
                ->get(['id', 'code']);

            $missing = array_diff($methodCodes, $types->pluck('code')->all());

            if ($missing !== []) {
                return $this->unprocessable('The given data was invalid.', [
                    'payment_methods' => ['These payment methods do not exist or are not active: '.implode(', ', $missing).'.'],
                ]);
            }

            $before = ServicePaymentPolicy::allowedMethodsFor($serviceIdBinary)->pluck('code')->all();

            $now = now();

            DB::table('service_payment_methods')->where('service_id', $serviceIdBinary)->update(['is_active' => 0, 'updated_at' => $now]);

            foreach ($types as $type) {
                $existing = DB::table('service_payment_methods')
                    ->where('service_id', $serviceIdBinary)
                    ->where('payment_method_type_id', $type->id)
                    ->first(['id', 'created_at']);

                if ($existing === null) {
                    DB::table('service_payment_methods')->insert([
                        'id' => UuidBinary::toBinary(UuidBinary::generate()),
                        'service_id' => $serviceIdBinary,
                        'payment_method_type_id' => $type->id,
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('service_payment_methods')->where('id', $existing->id)->update(['is_active' => 1, 'updated_at' => $now]);
                }
            }

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_PAYMENT_METHODS_CHANGED',
                'SERVICE',
                $serviceUuid,
                ['payment_methods' => $methodCodes, 'requires_prepayment' => ServicePaymentPolicy::requiresPrepayment($methodCodes)],
                ['payment_methods' => $before],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(200, 'Payment methods updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
