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
 * BLUE V1 Phase B25 - toggles a Service's `inspection_quote_credit_enabled`
 * flag (the ONE data-driven signal that Service participates in the
 * inspection -> follow-up-quote -> historical-credit workflow - never a
 * hardcoded `service.code === 'AC_MAINTENANCE'` check anywhere downstream).
 * Mirrors App\Actions\Admin\Service\AdminSetServiceCatalogPolicyAction
 * exactly (lock row, update, audit, re-present).
 *
 * Changing this policy affects FUTURE quote-eligibility checks only (see
 * App\Actions\Admin\Booking\AdminCreateRepairQuoteAction, which reads this
 * flag live at quote-creation time) - it never touches an existing
 * `booking_item_repair_quotes` row, whose own amounts remain historically
 * immutable regardless of a later policy flip.
 */
final class AdminSetServiceInspectionQuotePolicyAction
{
    use BuildsCartResult;

    public function handle(Request $request, User $actor, string $serviceUuid, bool $enabled): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        return DB::transaction(function () use ($request, $actor, $serviceUuid, $serviceIdBinary, $enabled): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first(['id', 'inspection_quote_credit_enabled']);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            DB::table('services')->where('id', $serviceIdBinary)->update([
                'inspection_quote_credit_enabled' => $enabled ? 1 : 0,
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_INSPECTION_QUOTE_POLICY_CHANGED',
                'SERVICE',
                $serviceUuid,
                ['inspection_quote_credit_enabled' => $enabled],
                ['inspection_quote_credit_enabled' => (bool) $service->inspection_quote_credit_enabled],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(200, 'Inspection quote policy updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
