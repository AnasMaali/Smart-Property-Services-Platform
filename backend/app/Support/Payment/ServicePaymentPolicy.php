<?php

namespace App\Support\Payment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B24 - the ONE place a Service's allowed payment methods are
 * ever read. Deliberately has no `requires_prepayment` input or output
 * column to read - that fact is always COMPUTED as
 * `! allowedCodes.contains('PAY_ON_SITE')` (see requiresPrepayment()), never
 * stored, so "a prepayment-required Service can never allow PAY_ON_SITE" is
 * a structural truth rather than a rule that could be violated by
 * inconsistent data.
 */
final class ServicePaymentPolicy
{
    /**
     * Active payment methods this Service currently allows, ordered by the
     * lookup's own display_order. Never includes an inactive
     * `payment_method_types` row even if `service_payment_methods.is_active`
     * is 1 - a globally-retired method type is unavailable everywhere.
     *
     * @return Collection<int, array{code: string, name: string}>
     */
    public static function allowedMethodsFor(string $serviceIdBinary): Collection
    {
        return DB::table('service_payment_methods')
            ->join('payment_method_types', 'payment_method_types.id', '=', 'service_payment_methods.payment_method_type_id')
            ->where('service_payment_methods.service_id', $serviceIdBinary)
            ->where('service_payment_methods.is_active', 1)
            ->where('payment_method_types.is_active', 1)
            ->orderBy('payment_method_types.display_order')
            ->get(['payment_method_types.code', 'payment_method_types.name'])
            ->map(fn ($row) => ['code' => $row->code, 'name' => $row->name])
            ->values();
    }

    /**
     * Batched variant for list/mixed-cart screens - one query instead of N.
     *
     * @param  array<int, string>  $serviceIdsBinary
     * @return Collection<string, Collection<int, array{code: string, name: string}>> Keyed by hex service id.
     */
    public static function allowedMethodsForMany(array $serviceIdsBinary): Collection
    {
        if ($serviceIdsBinary === []) {
            return collect();
        }

        return DB::table('service_payment_methods')
            ->join('payment_method_types', 'payment_method_types.id', '=', 'service_payment_methods.payment_method_type_id')
            ->whereIn('service_payment_methods.service_id', $serviceIdsBinary)
            ->where('service_payment_methods.is_active', 1)
            ->where('payment_method_types.is_active', 1)
            ->orderBy('payment_method_types.display_order')
            ->get(['service_payment_methods.service_id', 'payment_method_types.code', 'payment_method_types.name'])
            ->groupBy(fn ($row) => bin2hex($row->service_id))
            ->map(fn (Collection $rows) => $rows->map(fn ($row) => ['code' => $row->code, 'name' => $row->name])->values());
    }

    /**
     * @param  Collection<int, array{code: string, name: string}>|array<int, string>  $allowedMethods  Either allowedMethodsFor()'s own shape or a plain list of codes.
     */
    public static function requiresPrepayment(Collection|array $allowedMethods): bool
    {
        $codes = collect($allowedMethods)->map(fn ($m) => is_array($m) ? $m['code'] : $m);

        return ! $codes->contains('PAY_ON_SITE');
    }
}
