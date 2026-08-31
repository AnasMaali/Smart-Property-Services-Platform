<?php

namespace App\Actions\Admin\Pricing;

use App\Actions\Admin\Pricing\Concerns\PersistsPricingRule;
use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminPricingSchemePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates one DRAFT `pricing_rules` row (with its condition groups/
 * conditions/tiers) atomically (BLUE V1 Phase B9). Only ever operates on a
 * DRAFT `pricing_scheme_versions` row - PUBLISHED/RETIRED versions are
 * immutable, matching the "Published v1 -> create new DRAFT v2 -> edit v2"
 * workflow the schema itself implies (a PUBLISHED version's rules feed live
 * customer price calculations; nothing may rewrite them in place).
 *
 * Validates only the field-level shape/CHECK-constraint-mirroring rules
 * (via CreateAdminPricingRuleRequest) plus lightweight FK-existence checks
 * here - shared with App\Actions\Admin\Pricing\AdminUpdatePricingRuleAction
 * via the App\Actions\Admin\Pricing\Concerns\PersistsPricingRule trait.
 * Deliberately does NOT reproduce App\Support\Pricing\
 * SchemePublishValidator's cross-row publish-readiness checks (duplicate
 * priorities within the version, cross-service option references, tier
 * sequence coverage, condition-value completeness) - a DRAFT rule may be
 * saved before it is fully publish-ready; SchemePublishValidator remains
 * the single, authoritative gate for "is this scheme safe to go live",
 * enforced again (never skipped) when AdminPublishPricingSchemeAction runs.
 *
 * A DRAFT rule may also be edited in place via
 * App\Actions\Admin\Pricing\AdminUpdatePricingRuleAction (atomic delete +
 * recreate under one transaction, keeping the same rule UUID) instead of
 * two separate delete-then-create calls.
 */
final class AdminCreatePricingRuleAction
{
    use BuildsCartResult;
    use PersistsPricingRule;

    public function handle(Request $request, User $actor, string $schemeUuid, array $payload): array
    {
        try {
            $versionIdBinary = UuidBinary::toBinary($schemeUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Pricing scheme version not found.');
        }

        return DB::transaction(function () use ($request, $actor, $schemeUuid, $versionIdBinary, $payload): array {
            $version = DB::table('pricing_scheme_versions')->where('id', $versionIdBinary)->lockForUpdate()->first();

            if ($version === null) {
                return $this->notFound('Pricing scheme version not found.');
            }

            if ($version->status !== 'DRAFT') {
                return $this->conflict('Only a DRAFT pricing scheme version may have rules added.');
            }

            $errors = $this->validateRuleShape($payload, $version->service_id);

            if ($errors !== []) {
                return $this->unprocessable('This rule cannot be saved.', $errors);
            }

            if (DB::table('pricing_rules')->where('pricing_scheme_version_id', $versionIdBinary)->where('rule_code', $payload['rule_code'])->exists()) {
                return $this->conflict('A rule with this rule_code already exists on this scheme version.');
            }

            if (DB::table('pricing_rules')->where('pricing_scheme_version_id', $versionIdBinary)->where('priority', $payload['priority'])->exists()) {
                return $this->conflict('A rule with this priority already exists on this scheme version.');
            }

            $ruleUuid = UuidBinary::generate();
            $now = now();

            $this->persistRule($ruleUuid, $versionIdBinary, $payload, $now);

            AdminAuditLogger::record(
                $request,
                $actor,
                'PRICING_RULE_CREATED',
                'PRICING_SCHEME_VERSION',
                $schemeUuid,
                ['rule_uuid' => $ruleUuid, 'rule_code' => $payload['rule_code'], 'effect_type' => $payload['effect_type']],
            );

            $updated = AdminGetPricingSchemeAction::loadForDetail($schemeUuid);

            return $this->ok(201, 'Pricing rule created successfully.', ['pricing_scheme' => AdminPricingSchemePresenter::detail($updated)]);
        });
    }
}
