<?php

namespace App\Actions\Admin\Pricing;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingSchemeRepository;
use App\Support\Pricing\PricingSchemeSelector;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * BLUE V1 Phase B23 - the ONE simplified "Current Price" convenience Admin
 * operators use instead of hand-authoring pricing-scheme rules directly.
 * Never a second pricing authority: this is a thin orchestration entirely
 * on top of the EXISTING canonical draft -> rule -> publish flow
 * (App\Actions\Admin\Pricing\AdminCreatePricingSchemeDraftAction /
 * AdminCreatePricingRuleAction / AdminPublishPricingSchemeAction /
 * App\Support\Pricing\SchemePublishValidator) - every one of those classes
 * is called exactly as written, never re-implemented or bypassed.
 *
 * What "setting the current price" actually does, atomically:
 *   1. Creates a new DRAFT pricing_scheme_version for (service, AED).
 *   2. Copies forward every EXISTING rule from the currently-effective
 *      PUBLISHED version (if any) into the new draft UNCHANGED, except the
 *      reserved `rule_code = 'BASE_PRICE'` rule (priority 1, unconditional
 *      SET_PRICE) - so any legitimate option/choice-conditional pricing an
 *      Admin already configured through the advanced pricing-rule screen
 *      keeps working after a simple base-price edit.
 *   3. Writes the new BASE_PRICE rule with the given amount.
 *   4. RETIRES the previously-PUBLISHED open-ended version (status ->
 *      RETIRED, effective_to -> now()) - this is bookkeeping on the
 *      version's own validity WINDOW only, never a rewrite of its rules/
 *      amounts, and never touches any `booking_items.pricing_scheme_
 *      version_id` that already points at it (historical Bookings keep
 *      reading that exact, still-fully-intact row forever). Without this
 *      step, publish() would reject the new version - two open-ended
 *      PUBLISHED versions for the same service+currency can never coexist
 *      (`uq_pricing_scheme_versions_open_ended` / SchemePublishValidator's
 *      own overlap check).
 *   5. Publishes the new draft (effective_from = now(), open-ended) through
 *      the existing AdminPublishPricingSchemeAction - the SAME validated,
 *      row-locking, overlap-checked publish operation every other Admin
 *      pricing change already goes through.
 *
 * If ANY step fails, the whole thing rolls back - a Service can never be
 * left with two live open-ended versions, an unpublished half-configured
 * draft masquerading as current, or a retired-but-unreplaced price.
 */
final class AdminSetServiceCurrentPriceAction
{
    use BuildsCartResult;

    private const BASE_PRICE_RULE_CODE = 'BASE_PRICE';

    public function __construct(
        private readonly AdminCreatePricingSchemeDraftAction $createDraft = new AdminCreatePricingSchemeDraftAction,
        private readonly AdminCreatePricingRuleAction $createRule = new AdminCreatePricingRuleAction,
        private readonly AdminPublishPricingSchemeAction $publish = new AdminPublishPricingSchemeAction,
        private readonly PricingSchemeRepository $repository = new PricingSchemeRepository,
        private readonly PricingSchemeSelector $selector = new PricingSchemeSelector,
    ) {}

    public function handle(Request $request, User $actor, string $serviceUuid, string $currentPrice): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        return DB::transaction(function () use ($request, $actor, $serviceUuid, $serviceIdBinary, $currentPrice): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first(['id', 'original_price']);

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            if ($service->original_price !== null && bccomp((string) $service->original_price, $currentPrice, 6) < 0) {
                return $this->unprocessable('The given data was invalid.', [
                    'current_price' => ["The current price ({$currentPrice}) cannot exceed the original price ({$service->original_price})."],
                ]);
            }

            $currencyCode = DefaultCurrency::code();
            $currencyId = (int) DB::table('currencies')->where('code', $currencyCode)->value('id');

            $existingVersions = $this->repository->schemeVersionsFor($serviceIdBinary, $currencyId);
            $currentVersion = $this->selector->selectCurrent($existingVersions, now());
            $previousAmount = null;

            $carryForwardRules = [];

            if ($currentVersion !== null) {
                $rules = $this->repository->rulesForSchemeVersion(UuidBinary::toBinary($currentVersion['id']));

                foreach ($rules as $rule) {
                    // The "base price" being replaced is identified by
                    // ROLE (an unconditional SET_PRICE rule - i.e. no
                    // condition_groups, so it always fires and always wins
                    // the running total per App\Support\Pricing\
                    // PricingRuleEvaluator's last-SET_PRICE-wins semantics),
                    // never by rule_code alone - a scheme authored through
                    // the advanced pricing-rule screen (App\Actions\Admin\
                    // Pricing\AdminCreatePricingRuleAction) may give its
                    // unconditional base rule any rule_code. Carrying such
                    // a rule forward unchanged would leave it evaluating
                    // AFTER the new priority-1 BASE_PRICE rule and silently
                    // overwriting it back to the old amount - checkout would
                    // never actually see the new current price.
                    $isUnconditionalSetPrice = $rule['effect_type'] === 'SET_PRICE' && ($rule['condition_groups'] ?? []) === [];

                    if ($rule['rule_code'] === self::BASE_PRICE_RULE_CODE || $isUnconditionalSetPrice) {
                        $previousAmount ??= $rule['effect_amount'];

                        continue;
                    }

                    unset($rule['id']);
                    $carryForwardRules[] = $rule;
                }
            }

            $draftResult = $this->createDraft->handle($request, $actor, $serviceUuid, $currencyCode);

            if (! $draftResult['success']) {
                return $draftResult;
            }

            $draftUuid = $draftResult['data']['pricing_scheme']['uuid'];

            foreach ($carryForwardRules as $rule) {
                $ruleResult = $this->createRule->handle($request, $actor, $draftUuid, $rule);

                if (! $ruleResult['success']) {
                    return $this->unprocessable(
                        'This service has existing pricing rules that could not be carried forward to the new price - manage pricing directly via the advanced pricing screen.',
                        $ruleResult['errors'] ?? [],
                    );
                }
            }

            $baseRuleResult = $this->createRule->handle($request, $actor, $draftUuid, [
                'rule_code' => self::BASE_PRICE_RULE_CODE,
                'label' => 'Base price',
                'priority' => 1,
                'effect_type' => 'SET_PRICE',
                'effect_amount' => $currentPrice,
                'stop_processing' => false,
            ]);

            if (! $baseRuleResult['success']) {
                return $this->unprocessable(
                    'The base price rule could not be saved - priority 1 is reserved for it and may already be used by another pricing rule on this service.',
                    $baseRuleResult['errors'] ?? [],
                );
            }

            $now = now();

            if ($currentVersion !== null) {
                // `pricing_scheme_versions` rows are written through
                // DB::table() (not Eloquent), so every DateTimeInterface
                // binding is formatted via the query grammar's default
                // getDateFormat() ('Y-m-d H:i:s') regardless of the
                // datetime(6) column's own microsecond capacity - see
                // Illuminate\Database\Connection::prepareBindings(). Two
                // "Set Current Price" calls close enough together can
                // therefore compute a `$now` that rounds to the SAME whole
                // second as the version-being-retired's own `effective_from`
                // (itself written the same truncated way), which would trip
                // `chk_pricing_scheme_versions_period`'s strict `effective_to
                // > effective_from`.
                //
                // The fix nudges ONLY the RETIRED row's `effective_to` (never
                // the new version's `effective_from`, which must stay the
                // real "now" so the new price is selectable immediately, not
                // a moment in the future). This is safe: `open_ended_marker`
                // (the UNIQUE constraint backing "never two open-ended
                // PUBLISHED versions") is generated only for status=PUBLISHED,
                // and App\Support\Pricing\PricingSchemeSelector::
                // selectCurrent() skips every non-PUBLISHED row outright - so
                // a RETIRED row's exact `effective_to` timestamp can never
                // again affect which version is "current" or cause an
                // overlap, regardless of its precision.
                $previousEffectiveFrom = Carbon::parse($currentVersion['effective_from']);
                $retiredAt = $now->format('Y-m-d H:i:s') <= $previousEffectiveFrom->format('Y-m-d H:i:s')
                    ? $previousEffectiveFrom->copy()->addSecond()
                    : $now;

                DB::table('pricing_scheme_versions')
                    ->where('id', UuidBinary::toBinary($currentVersion['id']))
                    ->update(['status' => 'RETIRED', 'effective_to' => $retiredAt, 'updated_at' => $now]);
            }

            try {
                $publishResult = $this->publish->handle($request, $actor, $draftUuid, $now, null);
            } catch (RuntimeException $exception) {
                return $this->unprocessable($exception->getMessage());
            }

            if (! $publishResult['success']) {
                return $publishResult;
            }

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CURRENT_PRICE_CHANGED',
                'SERVICE',
                $serviceUuid,
                ['current_price' => $currentPrice, 'pricing_scheme_version_uuid' => $draftUuid],
                ['current_price' => $previousAmount],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(200, 'Current price updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
