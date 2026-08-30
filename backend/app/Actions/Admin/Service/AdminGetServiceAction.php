<?php

namespace App\Actions\Admin\Service;

use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B8. Options/Capabilities/Specializations/Media are
 * presented read-only (App\Support\Admin\AdminServicePresenter) rather than
 * exposed through any Admin mutation endpoint, after inspecting how each
 * one is actually consumed elsewhere in the codebase:
 *
 * - `service_capabilities` gates real Cart/Contract eligibility
 *   (App\Support\Pricing\ServiceCapabilities::has(), checked by
 *   App\Actions\Cart\AddCartItemAction for CART_ELIGIBLE and
 *   App\Actions\Contract\RequestContractAction for SUBSCRIPTION) - toggling
 *   one is a structural product-behavior change, not display metadata.
 * - `service_specializations` directly determines technician-candidate
 *   eligibility for a booking item (App\Actions\Admin\Technician\
 *   AdminListTechnicianCandidatesAction intersects it with
 *   `technician_specializations`) - an uninformed edit could silently make
 *   a Service unassignable or eligible for the wrong technicians.
 * - `service_options`/`service_option_choices`/their numeric/selection
 *   rules are validated by App\Support\Cart\CartSelectionValidator and
 *   priced by the flexible pricing engine; live `cart_item_option_selections` rows carry
 *   a hard FK to `service_options`/`service_option_choices` (`ON DELETE
 *   RESTRICT`), while completed `booking_item_option_selections` instead
 *   snapshot every field at booking time - so a Cart in progress depends on
 *   the option continuing to mean what it meant when it was added, and no
 *   safe "what happens to an in-progress Cart if this option changes"
 *   policy exists yet.
 * - `service_media` has a real storage/upload pipeline
 *   (`storage_key`/`file_size_bytes`) but nothing in this codebase writes
 *   to it yet - there is no existing secure upload flow to reuse, and BLUE
 *   V1 standing policy is to never invent one solely for this Admin page.
 * - `service_zones`/`service_zone_areas` has no relationship to
 *   `services` at all in the schema (it maps `areas` to a
 *   `service_zones` row, consumed only as a pricing-rule context
 *   dimension by App\Support\Checkout\CheckoutContextResolver) - there is
 *   no "which zones is this Service available in" data to show or mutate,
 *   so no Zones section exists on the Service detail page.
 *
 * Each of these remains a candidate for an explicit, reviewed mutation
 * Action in a future phase once its safety story (Cart/Booking impact,
 * technician-assignment impact) is confirmed - never a generic PATCH.
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
