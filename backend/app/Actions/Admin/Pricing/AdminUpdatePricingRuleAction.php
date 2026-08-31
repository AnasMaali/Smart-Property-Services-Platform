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
 * Atomically replaces one existing DRAFT `pricing_rules` row (with its
 * condition groups/conditions/tiers) in a single request - the same
 * delete + recreate semantics App\Actions\Admin\Pricing\
 * AdminDeletePricingRuleAction + AdminCreatePricingRuleAction already
 * establish (see AdminCreatePricingRuleAction's docblock: "editing a DRAFT
 * rule is delete + recreate"), just performed as one atomic operation under
 * a single transaction instead of two separate client calls, and keeping
 * the rule's own UUID stable across the edit rather than minting a new one.
 *
 * Only ever operates on a DRAFT `pricing_scheme_versions` row - a
 * PUBLISHED/RETIRED version's rules remain fully immutable, exactly like
 * create/delete. Shares field-level shape validation and the raw insert
 * path with AdminCreatePricingRuleAction via the
 * App\Actions\Admin\Pricing\Concerns\PersistsPricingRule trait - never a
 * second, divergent copy of those CHECK-constraint-mirroring rules.
 */
final class AdminUpdatePricingRuleAction
{
    use BuildsCartResult;
    use PersistsPricingRule;

    public function handle(Request $request, User $actor, string $schemeUuid, string $ruleUuid, array $payload): array
    {
        try {
            $versionIdBinary = UuidBinary::toBinary($schemeUuid);
            $ruleIdBinary = UuidBinary::toBinary($ruleUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Pricing rule not found.');
        }

        return DB::transaction(function () use ($request, $actor, $schemeUuid, $versionIdBinary, $ruleUuid, $ruleIdBinary, $payload): array {
            $version = DB::table('pricing_scheme_versions')->where('id', $versionIdBinary)->lockForUpdate()->first();

            if ($version === null) {
                return $this->notFound('Pricing scheme version not found.');
            }

            $existingRule = DB::table('pricing_rules')
                ->where('id', $ruleIdBinary)
                ->where('pricing_scheme_version_id', $versionIdBinary)
                ->lockForUpdate()
                ->first();

            if ($existingRule === null) {
                return $this->notFound('Pricing rule not found.');
            }

            if ($version->status !== 'DRAFT') {
                return $this->conflict('Only a DRAFT pricing scheme version\'s rules may be edited.');
            }

            $errors = $this->validateRuleShape($payload, $version->service_id);

            if ($errors !== []) {
                return $this->unprocessable('This rule cannot be saved.', $errors);
            }

            $duplicateCode = DB::table('pricing_rules')
                ->where('pricing_scheme_version_id', $versionIdBinary)
                ->where('rule_code', $payload['rule_code'])
                ->where('id', '<>', $ruleIdBinary)
                ->exists();

            if ($duplicateCode) {
                return $this->conflict('A rule with this rule_code already exists on this scheme version.');
            }

            $duplicatePriority = DB::table('pricing_rules')
                ->where('pricing_scheme_version_id', $versionIdBinary)
                ->where('priority', $payload['priority'])
                ->where('id', '<>', $ruleIdBinary)
                ->exists();

            if ($duplicatePriority) {
                return $this->conflict('A rule with this priority already exists on this scheme version.');
            }

            $oldSnapshot = [
                'rule_code' => $existingRule->rule_code,
                'label' => $existingRule->label,
                'priority' => (int) $existingRule->priority,
                'effect_type' => $existingRule->effect_type,
                'effect_amount' => $existingRule->effect_amount === null ? null : (string) $existingRule->effect_amount,
                'stop_processing' => (bool) $existingRule->stop_processing,
            ];

            // Cascade-deletes condition groups/conditions/condition values/
            // tiers via the existing ON DELETE CASCADE foreign keys, exactly
            // like AdminDeletePricingRuleAction.
            DB::table('pricing_rules')->where('id', $ruleIdBinary)->delete();

            $now = now();

            $this->persistRule($ruleUuid, $versionIdBinary, $payload, $now);

            AdminAuditLogger::record(
                $request,
                $actor,
                'PRICING_RULE_UPDATED',
                'PRICING_SCHEME_VERSION',
                $schemeUuid,
                [
                    'rule_uuid' => $ruleUuid,
                    'rule_code' => $payload['rule_code'],
                    'label' => $payload['label'],
                    'priority' => $payload['priority'],
                    'effect_type' => $payload['effect_type'],
                    'effect_amount' => $payload['effect_amount'] ?? null,
                    'stop_processing' => (bool) $payload['stop_processing'],
                ],
                $oldSnapshot,
            );

            $updated = AdminGetPricingSchemeAction::loadForDetail($schemeUuid);

            return $this->ok(200, 'Pricing rule updated successfully.', ['pricing_scheme' => AdminPricingSchemePresenter::detail($updated)]);
        });
    }
}
