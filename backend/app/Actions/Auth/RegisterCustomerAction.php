<?php

namespace App\Actions\Auth;

use App\Models\CustomerProfile;
use App\Models\OtpVerification;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\Otp\OtpDeliveryChannel;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class RegisterCustomerAction
{
    public function __construct(private readonly OtpDeliveryChannel $otpDelivery) {}

    /**
     * Create the user, profile, customer profile, service interests, role
     * assignment, and initial phone-verification OTP in a single atomic
     * transaction. Any failure rolls back every row created so far.
     *
     * @param  array{
     *     full_name: string,
     *     phone_number: string,
     *     email: string,
     *     city_id: int,
     *     area_id: int,
     *     property_relationship_type_id: int,
     *     service_interests: array<int, int>,
     * }  $data
     * @return array{
     *     user_uuid: string,
     *     full_name: string,
     *     phone_number: string,
     *     email: string,
     *     account_status: string,
     *     phone_verified: bool,
     *     otp_verification_uuid: string,
     *     otp_expires_at: string,
     * }
     */
    public function handle(array $data): array
    {
        $result = DB::transaction(function () use ($data) {
            $accountStatusId = $this->lookupId('user_account_statuses', 'PENDING_VERIFICATION');
            $customerRoleId = $this->lookupId('roles', 'CUSTOMER');
            $otpPurposeId = $this->lookupId('otp_verification_purposes', 'PHONE_VERIFICATION');
            $otpPendingStatusId = $this->lookupId('otp_verification_statuses', 'PENDING');

            $userUuid = UuidBinary::generate();

            User::create([
                'id' => $userUuid,
                'phone_number' => $data['phone_number'],
                'email' => $data['email'],
                // OTP-only customers never authenticate with a password.
                // Store an unusable hash so the column constraint is satisfied.
                'password_hash' => Hash::make(Str::random(64)),
                'account_status_id' => $accountStatusId,
                'phone_verified_at' => null,
            ]);

            UserProfile::create([
                'user_id' => $userUuid,
                'full_name' => $data['full_name'],
            ]);

            CustomerProfile::create([
                'user_id' => $userUuid,
                'property_relationship_type_id' => $data['property_relationship_type_id'],
                'area_id' => $data['area_id'],
            ]);

            $now = now();
            DB::table('customer_service_interests')->insert(
                collect($data['service_interests'])
                    ->unique()
                    ->map(fn (int $serviceCategoryId) => [
                        'customer_user_id' => UuidBinary::toBinary($userUuid),
                        'service_category_id' => $serviceCategoryId,
                        'created_at' => $now,
                    ])
                    ->values()
                    ->all()
            );

            DB::table('user_roles')->insert([
                'user_id' => UuidBinary::toBinary($userUuid),
                'role_id' => $customerRoleId,
                'assigned_by_user_id' => null,
                'assigned_at' => $now,
            ]);

            // SECURITY (future OTP delivery): the raw code below must never be
            // serialized into a persistent queue payload, cache, or log. A future
            // SMS sender may only receive it in memory, after this transaction
            // commits, then must discard it immediately after dispatch.
            $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otpExpiresAt = $now->copy()->addMinutes(5);
            $otpUuid = UuidBinary::generate();

            OtpVerification::create([
                'id' => $otpUuid,
                'user_id' => $userUuid,
                'purpose_id' => $otpPurposeId,
                'status_id' => $otpPendingStatusId,
                'target_phone_number' => $data['phone_number'],
                'code_hash' => Hash::make($otpCode),
                'failed_attempt_count' => 0,
                'max_attempts' => 5,
                'expires_at' => $otpExpiresAt,
            ]);

            return [
                'user_uuid' => $userUuid,
                'full_name' => $data['full_name'],
                'phone_number' => $data['phone_number'],
                'email' => $data['email'],
                'account_status' => 'PENDING_VERIFICATION',
                'phone_verified' => false,
                'otp_verification_uuid' => $otpUuid,
                'otp_expires_at' => $otpExpiresAt->toIso8601String(),
                'otp_raw_code' => $otpCode,
                'otp_expires_at_carbon' => $otpExpiresAt,
            ];
        });

        $rawOtp = $result['otp_raw_code'];
        $rawOtpExpiresAt = $result['otp_expires_at_carbon'];
        unset($result['otp_raw_code'], $result['otp_expires_at_carbon']);

        // Delivered only after the transaction above has committed - the
        // raw code is never handed to a delivery channel for a row that
        // might still be rolled back.
        $this->otpDelivery->deliver('PHONE_VERIFICATION', $result['phone_number'], $rawOtp, $rawOtpExpiresAt);
        unset($rawOtp);

        return $result;
    }

    private function lookupId(string $table, string $code): int
    {
        $id = DB::table($table)->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: {$table}.code = {$code}");
        }

        return (int) $id;
    }
}
