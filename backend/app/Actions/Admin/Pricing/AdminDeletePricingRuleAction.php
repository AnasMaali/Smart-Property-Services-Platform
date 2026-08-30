<?php

namespace App\Actions\Admin\Pricing;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminPricingSchemePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deletes one DRAFT `pricing_rules` row - its condition groups/conditions/
 * condition values/tiers cascade-delete via the existing `ON DELETE CASCADE`
 * foreign keys (database/blue_v1_schema.sql), so nothing extra is deleted
 * here manually. Only ever operates on a DRAFT scheme version - a
 * PUBLISHED/RETIRED version's rules are immutable.
 */
final class AdminDeletePricingRuleAction
{
    use BuildsCartResult;

    public function handle(Request $request, User $actor, string $schemeUuid, string $ruleUuid): array
    {
        try {
            $versionIdBinary = UuidBinary::toBinary($schemeUuid);
            $ruleIdBinary = UuidBinary::toBinary($ruleUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Pricing rule not found.');
        }

        return DB::transaction(function () use ($request, $actor, $schemeUuid, $versionIdBinary, $ruleUuid, $ruleIdBinary): array {
            $version = DB::table('pricing_scheme_versions')->where('id', $versionIdBinary)->lockForUpdate()->first();

            if ($version === null) {
                return $this->notFound('Pricing scheme version not found.');
            }

            $rule = DB::table('pricing_rules')
                ->where('id', $ruleIdBinary)
                ->where('pricing_scheme_version_id', $versionIdBinary)
                ->first(['id', 'rule_code']);

            if ($rule === null) {
                return $this->notFound('Pricing rule not found.');
            }

            if ($version->status !== 'DRAFT') {
                return $this->conflict('Only a DRAFT pricing scheme version\'s rules may be deleted.');
            }

            DB::table('pricing_rules')->where('id', $ruleIdBinary)->delete();

            AdminAuditLogger::record(
                $request,
                $actor,
                'PRICING_RULE_DELETED',
                'PRICING_SCHEME_VERSION',
                $schemeUuid,
                ['rule_uuid' => $ruleUuid, 'rule_code' => $rule->rule_code],
            );

            $updated = AdminGetPricingSchemeAction::loadForDetail($schemeUuid);

            return $this->ok(200, 'Pricing rule deleted successfully.', ['pricing_scheme' => AdminPricingSchemePresenter::detail($updated)]);
        });
    }
}
