<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B11. Presents the exact canonical `ratings` row (one per
 * completed Booking, at most - `ratings.booking_id` is its own primary
 * key) - never a second interpretation of it. Customer resolution reuses
 * the exact `bookings -> carts -> customer_user_id` join
 * App\Support\Admin\AdminBookingPresenter already established, rather than
 * inventing a new path to "the customer for a Booking".
 */
final class AdminRatingPresenter
{
    /**
     * $rows must already carry `carts.customer_user_id` alongside the raw
     * `ratings`/`bookings` columns (see
     * App\Actions\Admin\Rating\AdminListRatingsAction).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $customerIds = $rows->pluck('customer_user_id')->unique()->values()->all();
        $customers = self::customerSummaries($customerIds);

        return $rows->map(function (object $row) use ($customers) {
            $customer = $customers->get($row->customer_user_id);

            return [
                'booking_uuid' => UuidBinary::toString($row->booking_id),
                'booking_number' => $row->booking_number,
                'rating_value' => (int) $row->rating_value,
                'comment' => $row->comment,
                'customer' => $customer === null ? null : [
                    'uuid' => UuidBinary::toString($row->customer_user_id),
                    'full_name' => $customer->full_name,
                ],
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * $row must carry `carts.customer_user_id` and
     * `booking_statuses.code as booking_status` alongside the raw
     * `ratings`/`bookings` columns (see
     * App\Actions\Admin\Rating\AdminGetRatingAction).
     *
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $customer = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('users.id', $row->customer_user_id)
            ->first(['users.id', 'users.phone_number', 'user_profiles.full_name']);

        $services = DB::table('booking_items')
            ->where('booking_id', $row->booking_id)
            ->orderBy('display_order')
            ->pluck('service_name_snapshot')
            ->all();

        return [
            'booking_uuid' => UuidBinary::toString($row->booking_id),
            'booking_number' => $row->booking_number,
            'booking_status' => $row->booking_status,
            'rating_value' => (int) $row->rating_value,
            'comment' => $row->comment,
            'customer' => $customer === null ? null : [
                'uuid' => UuidBinary::toString($customer->id),
                'full_name' => $customer->full_name,
                'phone_number' => $customer->phone_number,
            ],
            'services' => $services,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, string>  $customerIdsBinary
     */
    private static function customerSummaries(array $customerIdsBinary): Collection
    {
        return DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $customerIdsBinary)
            ->get(['users.id', 'user_profiles.full_name'])
            ->keyBy('id');
    }
}
