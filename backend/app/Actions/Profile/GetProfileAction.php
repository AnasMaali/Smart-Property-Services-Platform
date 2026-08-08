<?php

namespace App\Actions\Profile;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GetProfileAction
{
    /**
     * Reads the authenticated customer's full profile. Joins to areas/cities/
     * property_relationship_types/service_categories are plain joins with no
     * is_active filter, so a previously-selected reference row that has since
     * become inactive is still displayed correctly.
     *
     * @return array{
     *     user_uuid: string,
     *     full_name: string,
     *     email: string,
     *     phone_number: string,
     *     phone_verified: bool,
     *     account_status: string,
     *     location: array{city: array{id: int, code: string, name: string}, area: array{id: int, code: string, name: string}},
     *     property_relationship: array{id: int, code: string, name: string},
     *     service_interests: array<int, array{id: int, code: string, name: string}>,
     * }
     */
    public function handle(string $userUuid): array
    {
        $userId = UuidBinary::toBinary($userUuid);

        $profile = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->join('user_account_statuses', 'user_account_statuses.id', '=', 'users.account_status_id')
            ->join('customer_profiles', 'customer_profiles.user_id', '=', 'users.id')
            ->join('areas', 'areas.id', '=', 'customer_profiles.area_id')
            ->join('cities', 'cities.id', '=', 'areas.city_id')
            ->join('property_relationship_types', 'property_relationship_types.id', '=', 'customer_profiles.property_relationship_type_id')
            ->where('users.id', $userId)
            ->select([
                'users.email',
                'users.phone_number',
                'users.phone_verified_at',
                'user_account_statuses.code as account_status',
                'user_profiles.full_name',
                'areas.id as area_id',
                'areas.code as area_code',
                'areas.name as area_name',
                'cities.id as city_id',
                'cities.code as city_code',
                'cities.name as city_name',
                'property_relationship_types.id as property_relationship_type_id',
                'property_relationship_types.code as property_relationship_type_code',
                'property_relationship_types.name as property_relationship_type_name',
            ])
            ->first();

        if ($profile === null) {
            throw new RuntimeException("Customer profile not found for user {$userUuid}.");
        }

        $serviceInterests = DB::table('customer_service_interests')
            ->join('service_categories', 'service_categories.id', '=', 'customer_service_interests.service_category_id')
            ->where('customer_service_interests.customer_user_id', $userId)
            ->orderBy('service_categories.display_order')
            ->get(['service_categories.id', 'service_categories.code', 'service_categories.name'])
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();

        return [
            'user_uuid' => $userUuid,
            'full_name' => $profile->full_name,
            'email' => $profile->email,
            'phone_number' => $profile->phone_number,
            'phone_verified' => $profile->phone_verified_at !== null,
            'account_status' => $profile->account_status,
            'location' => [
                'city' => [
                    'id' => $profile->city_id,
                    'code' => $profile->city_code,
                    'name' => $profile->city_name,
                ],
                'area' => [
                    'id' => $profile->area_id,
                    'code' => $profile->area_code,
                    'name' => $profile->area_name,
                ],
            ],
            'property_relationship' => [
                'id' => $profile->property_relationship_type_id,
                'code' => $profile->property_relationship_type_code,
                'name' => $profile->property_relationship_type_name,
            ],
            'service_interests' => $serviceInterests,
        ];
    }
}
