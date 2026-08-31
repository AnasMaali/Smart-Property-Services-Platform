<?php

namespace App\Actions\Admin\Service;

use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B8, extended in Phase B23. `service_capabilities`
 * (App\Support\Admin\AdminServicePresenter) gained an explicit, reviewed
 * mutation Action of its own - App\Actions\Admin\Service\
 * AdminSetServiceCapabilitiesAction - in BLUE V1 Admin Service Capabilities
 * Management. It gates real Cart/Contract eligibility (App\Support\Pricing\
 * ServiceCapabilities::has(), checked by App\Actions\Cart\AddCartItemAction
 * for CART_ELIGIBLE and App\Actions\Contract\RequestContractAction for
 * SUBSCRIPTION), so that Action's mutation is deliberately FORWARD-LOOKING
 * only - see its own docblock for the full non-cascading safety story.
 * QUOTE_ONLY/EMERGENCY/REQUIRES_SITE_VISIT remain vocabulary-only, with no
 * runtime behavior anywhere in this codebase yet.
 *
 * Specializations/Options/Choices/Media all gained explicit, reviewed
 * mutation Actions in Phase B23 (App\Actions\Admin\Service\
 * AdminSetServiceSpecializationAction / AdminServiceOptionAction /
 * AdminServiceOptionChoiceAction / AdminServiceMediaAction) once each one's
 * Cart-in-progress/historical-Booking-snapshot safety story was confirmed -
 * see those classes' own docblocks for the specific FK-restrict/snapshot
 * reasoning each relies on. This Action still only ever reads the current
 * state of all of them; every mutation goes through its own Action.
 *
 * `service_zones`/`service_zone_areas` has no relationship to `services` at
 * all in the schema (it maps `areas` to a `service_zones` row, consumed
 * only as a pricing-rule context dimension by App\Support\Checkout\
 * CheckoutContextResolver) - there is no "which zones is this Service
 * available in" data to show or mutate, so no Zones section exists on the
 * Service detail page.
 */
final class AdminGetServiceAction
{
    use BuildsCartResult;

    public function handle(string $serviceUuid): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        $service = AdminServicePresenter::loadForDetail($serviceIdBinary);

        if ($service === null) {
            return $this->notFound('Service not found.');
        }

        return $this->ok(200, 'Service retrieved successfully.', ['service' => AdminServicePresenter::detail($service)]);
    }
}
