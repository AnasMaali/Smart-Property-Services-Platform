<?php

namespace App\Support\Pricing;

use App\Support\Uuid\UuidBinary;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single reusable pricing entry point every caller shares: service
 * -details preview, cart add/update/get, checkout validation, and (later)
 * booking-snapshot creation. It resolves the currently-effective PUBLISHED
 * pricing scheme for (service, currency, evaluation time) and hands its
 * rules to the pure PricingRuleEvaluator - there is exactly one calculation
 * algorithm in the system, this class only wires it to the database.
 *
 * No service-specific branch exists anywhere in this class: it never
 * inspects a service's identity, only its currently-published rule data.
 */
final class PricingEngine
{
    public function __construct(
        private readonly PricingSchemeRepository $repository = new PricingSchemeRepository,
        private readonly PricingSchemeSelector $selector = new PricingSchemeSelector,
        private readonly PricingRuleEvaluator $evaluator = new PricingRuleEvaluator,
    ) {}

    /**
     * @param  string  $serviceIdUuid  Standard UUID string.
     * @param  array<string, array<string, mixed>>  $selections  Keyed by service_option_id (UUID string). Each entry: ['type' => CHOICE|NUMERIC|BOOLEAN|TEXT, 'choice_ids' => string[], 'numeric_value' => ?string, 'boolean_value' => ?bool, 'text_value' => ?string].
     * @param  string  $currencyCode  ISO currency code, e.g. "AED".
     * @param  array<string, string>  $context  Resolved system context attribute values, keyed by attribute code (e.g. "SERVICE_ZONE" => "3"). Only attributes the caller has actually resolved should be present - omission means "not yet known", never a guess.
     */
    public function evaluate(
        string $serviceIdUuid,
        array $selections,
        int $quantity,
        string $currencyCode,
        array $context = [],
        ?CarbonInterface $at = null,
    ): PricingResult {
        $at ??= now();

        $currency = DB::table('currencies')->where('code', $currencyCode)->first(['id', 'code']);

        if ($currency === null) {
            return $this->unavailable($currencyCode, $quantity);
        }

        $serviceIdBinary = UuidBinary::toBinary($serviceIdUuid);

        $versions = $this->repository->schemeVersionsFor($serviceIdBinary, (int) $currency->id);
        $currentVersion = $this->selector->selectCurrent($versions, $at);

        if ($currentVersion === null) {
            return $this->unavailable($currency->code, $quantity);
        }

        $rules = $this->repository->rulesForSchemeVersion(UuidBinary::toBinary($currentVersion['id']));

        return $this->evaluator->evaluate(
            rules: $rules,
            selections: $selections,
            quantity: $quantity,
            context: $context,
            currencyCode: $currency->code,
            pricingSchemeVersion: $currentVersion['id'],
        );
    }

    /**
     * Batched preview for list screens: one currently-effective-scheme
     * lookup and one rule load for every service, instead of N calls to
     * evaluate(). Always evaluates with empty selections/context and
     * quantity 1, matching a catalog list's "current price" display - the
     * exact same PricingRuleEvaluator is still used underneath, so this is
     * not a second calculation algorithm, only a batched data path.
     *
     * @param  array<int, string>  $serviceIdUuids
     * @return array<string, PricingResult> Keyed by service UUID string.
     */
    public function previewMany(array $serviceIdUuids, string $currencyCode, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        $currency = DB::table('currencies')->where('code', $currencyCode)->first(['id', 'code']);

        if ($currency === null || $serviceIdUuids === []) {
            return array_fill_keys($serviceIdUuids, $this->unavailable($currencyCode, 1));
        }

        $serviceIdsBinary = array_map(UuidBinary::toBinary(...), $serviceIdUuids);

        $versionsByServiceKey = $this->repository->schemeVersionsForMany($serviceIdsBinary, (int) $currency->id);

        $currentVersionByServiceKey = $versionsByServiceKey->map(fn ($versions) => $this->selector->selectCurrent($versions, $at));

        $versionIdsBinary = $currentVersionByServiceKey->filter()->map(fn ($version) => UuidBinary::toBinary($version['id']))->values()->all();

        $rulesByVersionKey = $this->repository->rulesForSchemeVersions($versionIdsBinary);

        $results = [];

        foreach ($serviceIdUuids as $serviceIdUuid) {
            $serviceKey = bin2hex(UuidBinary::toBinary($serviceIdUuid));
            $currentVersion = $currentVersionByServiceKey->get($serviceKey);

            if ($currentVersion === null) {
                $results[$serviceIdUuid] = $this->unavailable($currency->code, 1);

                continue;
            }

            $rules = $rulesByVersionKey->get(bin2hex(UuidBinary::toBinary($currentVersion['id'])), []);

            $results[$serviceIdUuid] = $this->evaluator->evaluate(
                rules: $rules,
                selections: [],
                quantity: 1,
                context: [],
                currencyCode: $currency->code,
                pricingSchemeVersion: $currentVersion['id'],
            );
        }

        return $results;
    }

    /**
     * Evaluates one EXPLICITLY named `pricing_scheme_versions` row -
     * DRAFT, PUBLISHED, or RETIRED - instead of resolving "the currently
     * -effective PUBLISHED version" the way evaluate() always does. This is
     * the smallest safe extension supporting an Admin Pricing Preview that
     * can evaluate a not-yet-published DRAFT before it goes live: it never
     * touches App\Support\Pricing\PricingSchemeSelector or the effective
     * -date window at all, so it can never influence, and is never confused
     * with, what a real customer's Cart/Checkout/service-details preview
     * calculates via evaluate() above - those two selection paths stay
     * completely separate. Still delegates to the exact same
     * PricingRuleEvaluator - never a second calculation algorithm.
     *
     * @param  string  $serviceIdUuid  Standard UUID string.
     * @param  string  $pricingSchemeVersionUuid  Standard UUID string of the exact `pricing_scheme_versions` row to evaluate, regardless of its status.
     * @param  array<string, array<string, mixed>>  $selections  Same shape as evaluate().
     * @param  array<string, string>  $context  Same shape as evaluate().
     */
    public function evaluateForVersion(
        string $serviceIdUuid,
        string $pricingSchemeVersionUuid,
        array $selections,
        int $quantity,
        array $context = [],
    ): PricingResult {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceIdUuid);
            $versionIdBinary = UuidBinary::toBinary($pricingSchemeVersionUuid);
        } catch (InvalidArgumentException) {
            return $this->unavailable(null, $quantity);
        }

        $version = DB::table('pricing_scheme_versions')
            ->join('currencies', 'currencies.id', '=', 'pricing_scheme_versions.currency_id')
            ->where('pricing_scheme_versions.id', $versionIdBinary)
            ->where('pricing_scheme_versions.service_id', $serviceIdBinary)
            ->first(['pricing_scheme_versions.id', 'currencies.code as currency_code']);

        if ($version === null) {
            return $this->unavailable(null, $quantity);
        }

        $rules = $this->repository->rulesForSchemeVersion($versionIdBinary);

        return $this->evaluator->evaluate(
            rules: $rules,
            selections: $selections,
            quantity: $quantity,
            context: $context,
            currencyCode: $version->currency_code,
            pricingSchemeVersion: $pricingSchemeVersionUuid,
        );
    }

    private function unavailable(?string $currencyCode, int $quantity): PricingResult
    {
        return new PricingResult(
            status: PricingStatus::UNAVAILABLE,
            currencyCode: $currencyCode,
            pricingSchemeVersion: null,
            baseAmount: null,
            adjustments: [],
            unitTotal: null,
            quantity: $quantity,
            lineTotal: null,
        );
    }
}
