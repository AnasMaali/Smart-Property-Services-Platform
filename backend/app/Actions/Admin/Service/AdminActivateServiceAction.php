<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingSchemeRepository;
use App\Support\Pricing\PricingSchemeSelector;
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
 *
 * BLUE V1 Phase B23 - a Service may only go live once its configuration can
 * actually support a booking end-to-end, so this now enforces the same
 * "activation readiness" gate a real bug/incident would otherwise surface
 * downstream at checkout or technician-assignment time instead of here, up
 * front, as a deterministic 422:
 *   - its Category must itself be active (an inactive Category already
 *     hides its Services from the customer catalog - see
 *     App\Actions\ServiceCatalog\ListCategoryServicesAction - so an active
 *     Service under an inactive Category is a silent dead end, never a
 *     useful state);
 *   - it must have a currently-PUBLISHED, currently-effective pricing
 *     scheme version for AED (same App\Support\Pricing\PricingSchemeSelector
 *     ::selectCurrent() used by AdminSetServiceCurrentPriceAction) - without
 *     one, the pricing engine evaluates to UNAVAILABLE and the Service is
 *     unbookable;
 *   - it must have at least one active `service_specializations` row - see
 *     App\Actions\Technician\AssignTechnicianToBookingItemAction::
 *     requiredSpecializationIds(), which treats an empty result as
 *     SERVICE_SPECIALIZATION_NOT_CONFIGURED and refuses ANY technician
 *     assignment for the Service;
 *   - every active, required (is_required=1) SINGLE_SELECT/MULTI_SELECT
 *     option must have at least one active choice, or a customer facing a
 *     mandatory selection would have nothing to select.
 */
final class AdminActivateServiceAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly PricingSchemeRepository $pricingRepository = new PricingSchemeRepository,
        private readonly PricingSchemeSelector $pricingSelector = new PricingSchemeSelector,
    ) {}

    public function handle(Request $request, string $serviceUuid, User $actor): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor): array {
            $service = DB::table('services')
                ->join('service_categories', 'service_categories.id', '=', 'services.category_id')
                ->where('services.id', $serviceIdBinary)
                ->lockForUpdate()
                ->first(['services.id', 'services.is_active', 'service_categories.is_active as category_is_active']);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            if ((int) $service->is_active === 1) {
                return $this->ok(200, 'Service is already active.', ['service' => AdminServicePresenter::detail(AdminServicePresenter::loadForDetail($serviceIdBinary))]);
            }

            $blockers = $this->activationBlockers($serviceIdBinary, (bool) $service->category_is_active);

            if ($blockers !== []) {
                return $this->unprocessable('This service is not ready to be activated.', $blockers);
            }

            DB::table('services')->where('id', $serviceIdBinary)->update(['is_active' => 1, 'updated_at' => now()]);

            AdminAuditLogger::record($request, $actor, 'SERVICE_ACTIVATED', 'SERVICE', $serviceUuid);

            return $this->ok(200, 'Service activated successfully.', ['service' => AdminServicePresenter::detail(AdminServicePresenter::loadForDetail($serviceIdBinary))]);
        });
    }

    /**
     * @return array<int, string>
     */
    private function activationBlockers(string $serviceIdBinary, bool $categoryIsActive): array
    {
        $blockers = [];

        if (! $categoryIsActive) {
            $blockers[] = 'This service\'s category is inactive - activate the category first.';
        }

        $currencyCode = DefaultCurrency::code();
        $currencyId = (int) DB::table('currencies')->where('code', $currencyCode)->value('id');
        $existingVersions = $this->pricingRepository->schemeVersionsFor($serviceIdBinary, $currencyId);

        if ($this->pricingSelector->selectCurrent($existingVersions, now()) === null) {
            $blockers[] = "This service has no currently-published {$currencyCode} price - set a current price first.";
        }

        $hasActiveSpecialization = DB::table('service_specializations')
            ->where('service_id', $serviceIdBinary)
            ->where('is_active', 1)
            ->exists();

        if (! $hasActiveSpecialization) {
            $blockers[] = 'This service has no active specialization configured - technicians could never be assigned to it.';
        }

        $requiredOptionsMissingChoices = DB::table('service_options')
            ->join('service_option_types', 'service_option_types.id', '=', 'service_options.option_type_id')
            ->where('service_options.service_id', $serviceIdBinary)
            ->where('service_options.is_active', 1)
            ->where('service_options.is_required', 1)
            ->whereIn('service_option_types.code', ['SINGLE_SELECT', 'MULTI_SELECT'])
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('service_option_choices')
                    ->whereColumn('service_option_choices.service_option_id', 'service_options.id')
                    ->where('service_option_choices.is_active', 1);
            })
            ->pluck('service_options.name');

        foreach ($requiredOptionsMissingChoices as $optionName) {
            $blockers[] = "Required option \"{$optionName}\" has no active choice - add at least one choice or make it optional.";
        }

        return $blockers;
    }
}
