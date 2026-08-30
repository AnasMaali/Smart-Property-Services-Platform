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
 * BLUE V1 Phase B23 - sets `services.original_price`, the customer-facing
 * "before discount" reference amount. Deliberately ADDITIVE CATALOG
 * METADATA ONLY (see database/phase22_catalog_admin_management_migration.sql's
 * docblock) - never a second checkout-pricing authority. The one
 * cross-table invariant this DOES enforce ("original >= current") is
 * checked here against App\Support\Admin\AdminServicePresenter::
 * currentSellingPrice() - the SAME pricing-engine call every customer
 * catalog/cart/checkout call already uses, kept out of this Actions/Admin
 * class directly per this codebase's Admin/pricing-engine isolation
 * boundary (see Tests\Feature\Admin\AdminFinancialIsolationTest) - never a
 * second, divergent price calculation.
 */
final class AdminSetServiceOriginalPriceAction
{
    use BuildsCartResult;

    public function handle(Request $request, User $actor, string $serviceUuid, ?string $originalPrice): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor, $originalPrice): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first(['id', 'original_price']);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            if ($originalPrice !== null) {
                $currentPrice = AdminServicePresenter::currentSellingPrice($serviceUuid);

                if ($currentPrice !== null && bccomp($originalPrice, $currentPrice, 6) < 0) {
                    return $this->unprocessable('The given data was invalid.', [
                        'original_price' => ["The original price ({$originalPrice}) must be greater than or equal to the current selling price ({$currentPrice})."],
                    ]);
                }
            }

            DB::table('services')->where('id', $serviceIdBinary)->update([
                'original_price' => $originalPrice,
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_ORIGINAL_PRICE_CHANGED',
                'SERVICE',
                $serviceUuid,
                ['original_price' => $originalPrice],
                ['original_price' => $service->original_price === null ? null : (string) $service->original_price],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(200, 'Original price updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
