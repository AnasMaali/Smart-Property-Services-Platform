<?php

namespace App\Support\Admin;

use App\Support\Auth\AccountDeletionRequestStore;
use App\Support\Property\PropertyPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Admin-facing Customer JSON shape (BLUE V1 Phase B6) - a global,
 * cross-customer view built the same way every other Admin*Presenter in
 * this codebase is: reusing the exact canonical field sources
 * App\Actions\Profile\GetProfileAction already established (account
 * status, location, property relationship), never re-deriving them, and
 * reusing App\Support\Property\PropertyPresenter::present() verbatim for
 * each Property (that presenter is already ownership-independent - it
 * never reads or requires `customer_user_id` itself).
 *
 * Never exposes `password_hash`, any OTP/session/refresh-token/WebAuthn
 * material, or any raw binary(16) id - only through UuidBinary. Only a
 * `users` row that also has a `customer_profiles` row is ever presented
 * here - a pure-Admin account (no customer registration) is not a
 * "Customer" for this module's purposes.
 */
final class AdminCustomerPresenter
{
    /**
     * Batch-loaded Admin Customer list row shape - never issues a query
     * per customer. Every row in $rows must already carry
     * `user_profiles.full_name`, `user_account_statuses.code as
     * account_status`, and `customer_profiles.area_id` alongside the raw
     * `users` columns (see App\Actions\Admin\Customer\AdminListCustomersAction).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $userIds = $rows->pluck('id')->all();
        $areaIds = $rows->pluck('area_id')->unique()->values()->all();

        $areas = DB::table('areas')
            ->join('cities', 'cities.id', '=', 'areas.city_id')
            ->whereIn('areas.id', $areaIds)
            ->get(['areas.id', 'areas.name as area_name', 'cities.name as city_name'])
            ->keyBy('id');

        $activePropertyCounts = DB::table('customer_properties')
            ->whereIn('customer_user_id', $userIds)
            ->where('is_active', 1)
            ->selectRaw('customer_user_id, COUNT(*) as properties_count')
            ->groupBy('customer_user_id')
            ->pluck('properties_count', 'customer_user_id');

        $pendingDeletionUserIds = DB::table('customer_account_deletion_requests')
            ->whereIn('user_id', $userIds)
            ->whereNull('completed_at')
            ->pluck('user_id')
            ->flip();

        return $rows->map(function (object $row) use ($areas, $activePropertyCounts, $pendingDeletionUserIds): array {
            $area = $areas->get($row->area_id);

            return [
                'uuid' => UuidBinary::toString($row->id),
                'full_name' => $row->full_name,
                'phone_number' => $row->phone_number,
                'email' => $row->email,
                'account_status' => $row->account_status,
                'phone_verified' => $row->phone_verified_at !== null,
                'area' => $area === null ? null : [
                    'name' => $area->area_name,
                    'city_name' => $area->city_name,
                ],
                'active_properties_count' => (int) ($activePropertyCounts[$row->id] ?? 0),
                'deletion_pending' => isset($pendingDeletionUserIds[$row->id]),
                'last_login_at' => $row->last_login_at === null ? null : Carbon::parse($row->last_login_at)->toIso8601String(),
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ];
        })->all();
    }

    /**
     * Full Admin Customer detail shape - $row must carry
     * `user_profiles.full_name`, `user_account_statuses.code as
     * account_status`, and every `customer_profiles.*` column alongside
     * the raw `users` columns (see App\Actions\Admin\Customer\
     * AdminGetCustomerAction).
     *
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $area = DB::table('areas')
            ->join('cities', 'cities.id', '=', 'areas.city_id')
            ->join('countries', 'countries.id', '=', 'cities.country_id')
            ->where('areas.id', $row->area_id)
            ->first(['areas.id', 'areas.name as area_name', 'cities.name as city_name', 'countries.name as country_name']);

        $relationshipType = DB::table('property_relationship_types')
            ->where('id', $row->property_relationship_type_id)
            ->first(['code', 'name']);

        $properties = DB::table('customer_properties')
            ->where('customer_user_id', $row->id)
            ->orderByDesc('created_at')
            ->get();

        $deletionRequest = (new AccountDeletionRequestStore)->findPending($row->id);

        $userIdBinary = $row->id;

        $bookingsCount = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('carts.customer_user_id', $userIdBinary)
            ->count();

        $paymentsCount = DB::table('payment_attempts')
            ->join('carts', 'carts.id', '=', 'payment_attempts.cart_id')
            ->where('carts.customer_user_id', $userIdBinary)
            ->count();

        $contractsCount = DB::table('service_contracts')
            ->where('customer_user_id', $userIdBinary)
            ->count();

        return [
            'uuid' => UuidBinary::toString($row->id),
            'full_name' => $row->full_name,
            'phone_number' => $row->phone_number,
            'email' => $row->email,
            'account_status' => $row->account_status,
            'phone_verified' => $row->phone_verified_at !== null,
            'phone_verified_at' => $row->phone_verified_at === null ? null : Carbon::parse($row->phone_verified_at)->toIso8601String(),
            'last_login_at' => $row->last_login_at === null ? null : Carbon::parse($row->last_login_at)->toIso8601String(),
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
            'deleted_at' => $row->deleted_at === null ? null : Carbon::parse($row->deleted_at)->toIso8601String(),
            'account_deletion' => [
                'status' => $deletionRequest === null ? 'NONE' : 'PENDING',
                'requested_at' => $deletionRequest === null ? null : Carbon::parse($deletionRequest->requested_at)->toIso8601String(),
            ],
            'location' => $area === null ? null : [
                'area_name' => $area->area_name,
                'city_name' => $area->city_name,
                'country_name' => $area->country_name,
            ],
            'property_relationship' => $relationshipType === null ? null : [
                'code' => $relationshipType->code,
                'name' => $relationshipType->name,
            ],
            'properties' => $properties->map(PropertyPresenter::present(...))->all(),
            'activity' => [
                'bookings_count' => $bookingsCount,
                'payments_count' => $paymentsCount,
                'contracts_count' => $contractsCount,
                'properties_count' => $properties->count(),
            ],
        ];
    }
}
