<?php

namespace App\Actions\Admin\Pricing;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminPricingSchemePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Pricing\PricingSchemeRepository;
use App\Support\Pricing\PricingSchemeSelector;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Explicitly retires a PUBLISHED `pricing_scheme_versions` row - the
 * Admin-triggered transition into `RETIRED` that, before this Action,
 * existed only as an internal side effect of App\Actions\Admin\Pricing\
 * AdminSetServiceCurrentPriceAction retiring the version it replaces (see
 * that Action's docblock, step 4). This Action generalizes the exact same
 * "bookkeeping on the version's own validity WINDOW only, never a rewrite
 * of its rules/amounts" approach to a direct, reviewed Admin operation, and
 * never deletes the version or any of its rules/conditions/tiers - a
 * RETIRED row (and any `booking_items.pricing_scheme_version_id` already
 * pointing at it) stays fully readable forever.
 *
 * Safety gate: a version currently selected by App\Support\Pricing\
 * PricingSchemeSelector::selectCurrent() as "the" live pricing for its
 * (service, currency) may only be retired if another PUBLISHED version
 * would immediately become selectable in its place - otherwise retiring it
 * would leave that service+currency with no currently-effective pricing
 * (PricingStatus::UNAVAILABLE for every live Cart/Checkout evaluation),
 * which this Action refuses rather than silently break checkout. A
 * not-yet-started (future-dated) or already-lapsed (effective_to already
 * in the past) PUBLISHED version is never "currently selected", so
 * retiring one of those is always safe and never blocked.
 *
 * Idempotent: retiring an already-RETIRED version is a no-op success (no
 * new audit row), matching the existing Category/Service activate
 * -toggle convention. A DRAFT version cannot be retired - it was never
 * live; use AdminDeletePricingRuleAction/further drafting, or simply leave
 * it as an unpublished DRAFT.
 */
final class AdminRetirePricingSchemeVersionAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly PricingSchemeRepository $repository = new PricingSchemeRepository,
        private readonly PricingSchemeSelector $selector = new PricingSchemeSelector,
    ) {}

    public function handle(Request $request, User $actor, string $schemeUuid): array
    {
        try {
            $versionIdBinary = UuidBinary::toBinary($schemeUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Pricing scheme version not found.');
        }

        return DB::transaction(function () use ($request, $actor, $schemeUuid, $versionIdBinary): array {
            $version = DB::table('pricing_scheme_versions')->where('id', $versionIdBinary)->lockForUpdate()->first();

            if ($version === null) {
                return $this->notFound('Pricing scheme version not found.');
            }

            if ($version->status === 'RETIRED') {
                $current = AdminGetPricingSchemeAction::loadForDetail($schemeUuid);

                return $this->ok(200, 'Pricing scheme version is already retired.', ['pricing_scheme' => AdminPricingSchemePresenter::detail($current)]);
            }

            if ($version->status !== 'PUBLISHED') {
                return $this->conflict('Only a PUBLISHED pricing scheme version may be retired.');
            }

            // Lock every PUBLISHED sibling for the same service+currency
            // too, mirroring SchemePublishValidator::publish()'s own
            // locking, so a concurrent publish/retire can never race this
            // safety check.
            DB::table('pricing_scheme_versions')
                ->where('service_id', $version->service_id)
                ->where('currency_id', $version->currency_id)
                ->where('status', 'PUBLISHED')
                ->lockForUpdate()
                ->get();

            $now = now();
            $versionUuid = UuidBinary::toString($version->id);
            $siblingVersions = $this->repository->schemeVersionsFor($version->service_id, (int) $version->currency_id);
            $currentlyActive = $this->selector->selectCurrent($siblingVersions, $now);

            if ($currentlyActive !== null && $currentlyActive['id'] === $versionUuid) {
                $remainingVersions = array_values(array_filter($siblingVersions, fn (array $candidate) => $candidate['id'] !== $versionUuid));
                $replacement = $this->selector->selectCurrent($remainingVersions, $now);

                if ($replacement === null) {
                    return $this->conflict('Cannot retire: this is the only currently-active pricing for this service and currency. Publish a replacement version before retiring this one.');
                }
            }

            $oldEffectiveTo = $version->effective_to;
            $effectiveFrom = Carbon::parse($version->effective_from);
            $stillOpen = $version->effective_to === null || Carbon::parse($version->effective_to)->greaterThan($now);

            // Same whole-second-collision nudge as
            // AdminSetServiceCurrentPriceAction: pricing_scheme_versions is
            // written through DB::table() (not Eloquent), so a
            // DateTimeInterface binding is truncated to whole seconds
            // regardless of the datetime(6) column's own precision -
            // rounding $now down to the same second as $effectiveFrom would
            // otherwise trip chk_pricing_scheme_versions_period's strict
            // `effective_to > effective_from`.
            $retiredAt = $stillOpen
                ? ($now->format('Y-m-d H:i:s') <= $effectiveFrom->format('Y-m-d H:i:s') ? $effectiveFrom->copy()->addSecond() : $now)
                : Carbon::parse($version->effective_to);

            DB::table('pricing_scheme_versions')
                ->where('id', $versionIdBinary)
                ->update(['status' => 'RETIRED', 'effective_to' => $retiredAt, 'updated_at' => $now]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'PRICING_SCHEME_RETIRED',
                'PRICING_SCHEME_VERSION',
                $schemeUuid,
                ['status' => 'RETIRED', 'effective_to' => $retiredAt->toIso8601String()],
                ['status' => 'PUBLISHED', 'effective_to' => $oldEffectiveTo === null ? null : Carbon::parse($oldEffectiveTo)->toIso8601String()],
            );

            $updated = AdminGetPricingSchemeAction::loadForDetail($schemeUuid);

            return $this->ok(200, 'Pricing scheme version retired successfully.', ['pricing_scheme' => AdminPricingSchemePresenter::detail($updated)]);
        });
    }
}
