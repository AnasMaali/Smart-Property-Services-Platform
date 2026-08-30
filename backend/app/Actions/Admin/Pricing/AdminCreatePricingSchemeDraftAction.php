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
 * Creates a new DRAFT `pricing_scheme_versions` row for one service+currency
 * (BLUE V1 Phase B9). The schema places no limit on how many DRAFT versions
 * a service may have at once (the `uq_pricing_scheme_versions_open_ended`
 * unique key only ever constrains PUBLISHED, open-ended rows - see
 * `open_ended_marker`'s generation expression) - so this never needs to
 * check for an existing draft first. `effective_from`/`effective_to` are
 * intentionally never set here: the schema only requires them once a
 * version stops being DRAFT (`chk_pricing_scheme_versions_requires_from`),
 * and App\Support\Pricing\SchemePublishValidator::publish() is what sets
 * them, atomically, at publish time.
 */
final class AdminCreatePricingSchemeDraftAction
{
    use BuildsCartResult;

    public function handle(Request $request, User $actor, string $serviceUuid, string $currencyCode): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->unprocessable('The given data was invalid.', ['service_uuid' => ['The service uuid is invalid.']]);
        }

        return DB::transaction(function () use ($request, $actor, $serviceIdBinary, $serviceUuid, $currencyCode): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->first(['id']);

            if ($service === null) {
                return $this->unprocessable('The given data was invalid.', ['service_uuid' => ['This service does not exist.']]);
            }

            $currency = DB::table('currencies')->where('code', $currencyCode)->where('is_active', 1)->first(['id', 'code']);

            if ($currency === null) {
                return $this->unprocessable('The given data was invalid.', ['currency_code' => ['This currency does not exist or is not active.']]);
            }

            $now = now();
            $versionUuid = UuidBinary::generate();

            DB::table('pricing_scheme_versions')->insert([
                'id' => UuidBinary::toBinary($versionUuid),
                'service_id' => $serviceIdBinary,
                'currency_id' => $currency->id,
                'status' => 'DRAFT',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'PRICING_SCHEME_DRAFT_CREATED',
                'PRICING_SCHEME_VERSION',
                $versionUuid,
                ['service_uuid' => $serviceUuid, 'currency_code' => $currency->code],
            );

            $created = AdminGetPricingSchemeAction::loadForDetail($versionUuid);

            return $this->ok(201, 'Pricing scheme draft created successfully.', ['pricing_scheme' => AdminPricingSchemePresenter::detail($created)]);
        });
    }
}
