<?php

namespace App\Actions\Profile;

use App\Models\CustomerProfile;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UpdateProfileAction
{
    public function __construct(private readonly GetProfileAction $getProfileAction) {}

    /**
     * Applies a partial profile update in one transaction covering
     * users.email, user_profiles.full_name, customer_profiles.area_id /
     * property_relationship_type_id, and a full replace of
     * customer_service_interests when service_interests is supplied. Locks
     * the users/user_profiles/customer_profiles rows (in that order, matching
     * the parent-to-child FK order already used elsewhere in this codebase)
     * before mutating, so two concurrent PATCH calls for the same customer
     * cannot interleave partial writes.
     *
     * @param  array{
     *     full_name?: string,
     *     email?: string,
     *     area_id?: int,
     *     property_relationship_type_id?: int,
     *     service_interests?: array<int, int>,
     * }  $data
     */
    public function handle(string $userUuid, array $data): array
    {
        $userId = UuidBinary::toBinary($userUuid);

        return DB::transaction(function () use ($userId, $userUuid, $data) {
            $user = User::where('id', $userId)->lockForUpdate()->first();
            $userProfile = UserProfile::where('user_id', $userId)->lockForUpdate()->first();
            $customerProfile = CustomerProfile::where('user_id', $userId)->lockForUpdate()->first();

            if ($user === null || $userProfile === null || $customerProfile === null) {
                throw new RuntimeException("Customer profile not found for user {$userUuid}.");
            }

            if (array_key_exists('full_name', $data)) {
                $userProfile->full_name = $data['full_name'];
                $userProfile->save();
            }

            if (array_key_exists('email', $data)) {
                $user->email = $data['email'];
                $user->save();
            }

            if (array_key_exists('area_id', $data)) {
                $customerProfile->area_id = $data['area_id'];
            }

            if (array_key_exists('property_relationship_type_id', $data)) {
                $customerProfile->property_relationship_type_id = $data['property_relationship_type_id'];
            }

            if ($customerProfile->isDirty()) {
                $customerProfile->save();
            }

            if (array_key_exists('service_interests', $data)) {
                DB::table('customer_service_interests')->where('customer_user_id', $userId)->delete();

                $now = now();
                DB::table('customer_service_interests')->insert(
                    collect($data['service_interests'])
                        ->unique()
                        ->map(fn (int $serviceCategoryId) => [
                            'customer_user_id' => $userId,
                            'service_category_id' => $serviceCategoryId,
                            'created_at' => $now,
                        ])
                        ->values()
                        ->all()
                );
            }

            return $this->getProfileAction->handle($userUuid);
        });
    }
}
