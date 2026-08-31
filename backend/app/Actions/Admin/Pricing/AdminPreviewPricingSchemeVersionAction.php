<?php

namespace App\Actions\Admin\Pricing;

use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\CartSelectionValidator;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lets an Admin evaluate one EXPLICITLY named `pricing_scheme_versions` row
 * - most importantly a DRAFT, before it is ever published - through the
 * real pricing calculation engine, never a second/duplicated calculation in
 * this Action, a Controller, or JavaScript. Distinct from
 * App\Actions\Admin\Pricing\AdminPreviewServicePricingAction (which always
 * previews a Service's currently-effective PUBLISHED price): that Action's
 * selection path is completely untouched by this one - selecting a specific
 * version here can never affect, and is never confused with, what a real
 * customer's Cart/Checkout/service-details preview calculates for the same
 * Service.
 *
 * Selections are validated through the exact same
 * App\Support\Cart\CartSelectionValidator the real Cart Add/Update flow
 * uses - never a second, JS-side or otherwise duplicated shape validation.
 * The calculation itself is delegated to App\Support\Admin\
 * AdminServicePresenter::previewPricingForVersion() - the one Support-layer
 * call this codebase's Admin/pricing-engine isolation boundary permits (see
 * Tests\Feature\Admin\AdminFinancialIsolationTest - no class under
 * app/Actions/Admin/** may call the calculation engine directly).
 *
 * A pure read: no Booking, Payment, Cart, Cart Item, or any pricing row is
 * ever written, and no `pricing_scheme_versions` row's status/effective
 * dates are ever touched - previewing a DRAFT can never publish it or
 * otherwise change what a real customer is quoted.
 */
final class AdminPreviewPricingSchemeVersionAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly CartSelectionValidator $selectionValidator = new CartSelectionValidator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $optionsInput  Same shape App\Http\Requests\Cart\AddCartItemRequest accepts.
     * @param  array<string, string>  $context  Resolved pricing context attribute values, keyed by attribute code (e.g. "SERVICE_ZONE" => "3"), supplied explicitly by the Admin - never guessed/synthesized here.
     */
    public function handle(string $schemeUuid, int $quantity, array $optionsInput, array $context): array
    {
        try {
            $versionIdBinary = UuidBinary::toBinary($schemeUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Pricing scheme version not found.');
        }

        $version = DB::table('pricing_scheme_versions')->where('id', $versionIdBinary)->first(['id', 'service_id', 'status']);

        if ($version === null) {
            return $this->notFound('Pricing scheme version not found.');
        }

        $serviceUuid = UuidBinary::toString($version->service_id);

        $validation = $this->selectionValidator->validate($serviceUuid, $optionsInput);

        if ($validation['errors'] !== []) {
            return $this->unprocessable('One or more selected options are invalid.', $validation['errors']);
        }

        $pricing = AdminServicePresenter::previewPricingForVersion(
            $serviceUuid,
            $schemeUuid,
            $this->selectionValidator->toPricingSelections($validation['options']),
            $quantity,
            $context,
        );

        return $this->ok(200, 'Pricing preview computed successfully.', [
            'pricing_scheme_version' => ['uuid' => $schemeUuid, 'status' => $version->status],
            'pricing' => $pricing,
        ]);
    }
}
