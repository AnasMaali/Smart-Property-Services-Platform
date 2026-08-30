<?php

namespace App\Actions\Admin\Pricing;

use App\Support\Admin\AdminPricingSchemePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AdminGetPricingSchemeAction
{
    use BuildsCartResult;

    public function handle(string $schemeUuid): array
    {
        $version = self::loadForDetail($schemeUuid);

        if ($version === null) {
            return $this->notFound('Pricing scheme version not found.');
        }

        return $this->ok(200, 'Pricing scheme version retrieved successfully.', ['pricing_scheme' => AdminPricingSchemePresenter::detail($version)]);
    }

    public static function loadForDetail(string $schemeUuid): ?object
    {
        try {
            $idBinary = UuidBinary::toBinary($schemeUuid);
        } catch (InvalidArgumentException) {
            return null;
        }

        return DB::table('pricing_scheme_versions')
            ->join('services', 'services.id', '=', 'pricing_scheme_versions.service_id')
            ->join('currencies', 'currencies.id', '=', 'pricing_scheme_versions.currency_id')
            ->where('pricing_scheme_versions.id', $idBinary)
            ->select([
                'pricing_scheme_versions.id',
                'pricing_scheme_versions.service_id',
                'pricing_scheme_versions.currency_id',
                'pricing_scheme_versions.status',
                'pricing_scheme_versions.effective_from',
                'pricing_scheme_versions.effective_to',
                'pricing_scheme_versions.published_at',
                'pricing_scheme_versions.created_at',
                'pricing_scheme_versions.updated_at',
                'services.name as service_name',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol',
            ])
            ->first();
    }
}
