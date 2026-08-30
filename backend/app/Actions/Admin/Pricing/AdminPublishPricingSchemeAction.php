<?php

namespace App\Actions\Admin\Pricing;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminPricingSchemePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Pricing\SchemePublishValidator;
use App\Support\Uuid\UuidBinary;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Publishes a DRAFT pricing scheme version (BLUE V1 Phase B9) - the most
 * financially sensitive Admin Pricing mutation, since a successful publish
 * immediately changes what the real pricing engine calculates for real
 * customers. This Action never re-implements or duplicates
 * App\Support\Pricing\SchemePublishValidator's rules; it only calls
 * `validate()` (for a friendly error list before attempting the write) and
 * `publish()` (the real, already-transactional, row-locking, overlap
 * -checking publish operation) exactly as they are already written.
 *
 * Guards a check publish() itself does not: it never checks the version's
 * *current* status, so calling it on an already-PUBLISHED/RETIRED version
 * would silently rewrite its effective dates. This Action locks the
 * version first and rejects anything other than DRAFT before ever calling
 * publish() - preventing that misuse without touching the validator.
 */
final class AdminPublishPricingSchemeAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly SchemePublishValidator $validator = new SchemePublishValidator,
    ) {}

    public function handle(Request $request, User $actor, string $schemeUuid, Carbon $effectiveFrom, ?Carbon $effectiveTo): array
    {
        try {
            $versionIdBinary = UuidBinary::toBinary($schemeUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Pricing scheme version not found.');
        }

        return DB::transaction(function () use ($request, $actor, $schemeUuid, $versionIdBinary, $effectiveFrom, $effectiveTo): array {
            $version = DB::table('pricing_scheme_versions')->where('id', $versionIdBinary)->lockForUpdate()->first();

            if ($version === null) {
                return $this->notFound('Pricing scheme version not found.');
            }

            if ($version->status !== 'DRAFT') {
                return $this->conflict('Only a DRAFT pricing scheme version may be published.');
            }

            $errors = $this->validator->validate($versionIdBinary);

            if ($errors !== []) {
                return $this->unprocessable('This pricing scheme version cannot be published.', $errors);
            }

            try {
                $this->validator->publish($versionIdBinary, $effectiveFrom, $effectiveTo);
            } catch (RuntimeException $exception) {
                return $this->unprocessable($exception->getMessage());
            }

            AdminAuditLogger::record(
                $request,
                $actor,
                'PRICING_SCHEME_PUBLISHED',
                'PRICING_SCHEME_VERSION',
                $schemeUuid,
                [
                    'service_uuid' => UuidBinary::toString($version->service_id),
                    'effective_from' => $effectiveFrom->toIso8601String(),
                    'effective_to' => $effectiveTo?->toIso8601String(),
                ],
            );

            $updated = AdminGetPricingSchemeAction::loadForDetail($schemeUuid);

            return $this->ok(200, 'Pricing scheme version published successfully.', ['pricing_scheme' => AdminPricingSchemePresenter::detail($updated)]);
        });
    }
}
