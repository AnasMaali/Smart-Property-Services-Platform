<?php

namespace Tests\Feature\Technician\Concerns;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;

/**
 * Technician-assignment-specific fixture builders, layered on top of the
 * Phase 7A CreatesBookingFixtures - every assignment test needs a real Paid
 * Booking Item (a Booking Item cannot exist without a full successful
 * payment first) plus its own Technician/specialization data, which no
 * other Phase builds. Follows the same direct-insert, QA-labelled-row
 * convention as every other *Fixtures trait in this codebase - no factories
 * exist here.
 */
trait CreatesTechnicianFixtures
{
    use CreatesBookingFixtures;

    private static int $technicianFixtureSequence = 0;

    private function technicianStatusId(string $code): int
    {
        return (int) DB::table('technician_statuses')->where('code', $code)->value('id');
    }

    private function createSpecialization(array $overrides = []): int
    {
        self::$technicianFixtureSequence++;
        $sequence = self::$technicianFixtureSequence;
        $now = now();

        return DB::table('specializations')->insertGetId([
            'code' => $overrides['code'] ?? 'TECH_QA_SPEC_'.$sequence,
            'name' => $overrides['name'] ?? 'Technician QA Specialization '.$sequence,
            'description' => 'QA test fixture specialization, not real business data.',
            'display_order' => 900 + $sequence,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function linkServiceSpecialization(string $serviceUuid, int $specializationId, array $overrides = []): void
    {
        $now = now();

        DB::table('service_specializations')->insert([
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'specialization_id' => $specializationId,
            'is_primary' => $overrides['is_primary'] ?? 1,
            'display_order' => $overrides['display_order'] ?? 0,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function linkTechnicianSpecialization(string $technicianUuid, int $specializationId, array $overrides = []): void
    {
        $now = now();

        DB::table('technician_specializations')->insert([
            'technician_id' => UuidBinary::toBinary($technicianUuid),
            'specialization_id' => $specializationId,
            'is_primary' => $overrides['is_primary'] ?? 1,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createTechnician(array $overrides = []): string
    {
        self::$technicianFixtureSequence++;
        $sequence = self::$technicianFixtureSequence;
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('technicians')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'employee_code' => $overrides['employee_code'] ?? 'TECH_QA_'.$sequence,
            'status_id' => $overrides['status_id'] ?? $this->technicianStatusId('AVAILABLE'),
            'full_name' => $overrides['full_name'] ?? 'Technician QA '.$sequence,
            'phone_number' => $overrides['phone_number'] ?? '+97155'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'email' => $overrides['email'] ?? 'technician.qa.'.$sequence.'@example.com',
            'is_phone_visible_to_customer' => $overrides['is_phone_visible_to_customer'] ?? 0,
            'internal_note' => $overrides['internal_note'] ?? null,
            'status_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    /**
     * A Technician already carrying an active specialization, matching a
     * freshly-created service_specializations requirement - the common
     * "eligible technician" shape most assignment tests need.
     *
     * @return array{uuid: string, specialization_id: int}
     */
    private function createEligibleTechnician(int $specializationId, array $overrides = []): array
    {
        $uuid = $this->createTechnician($overrides);
        $this->linkTechnicianSpecialization($uuid, $specializationId);

        return ['uuid' => $uuid, 'specialization_id' => $specializationId];
    }

    private function createAdminUser(): string
    {
        self::$technicianFixtureSequence++;
        $sequence = self::$technicianFixtureSequence;
        $userUuid = UuidBinary::generate();
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => '+97158'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'email' => 'admin.qa.'.$sequence.'@example.com',
            'password_hash' => Hash::make('AdminTestPassw0rd'),
            'account_status_id' => (int) DB::table('user_account_statuses')->where('code', 'ACTIVE')->value('id'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Admin QA User '.$sequence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'role_id' => (int) DB::table('roles')->where('code', 'ADMIN')->value('id'),
            'assigned_by_user_id' => null,
            'assigned_at' => $now,
        ]);

        return $userUuid;
    }

    /**
     * Builds one full, paid Booking with exactly one Booking Item ready for
     * assignment (PENDING_ASSIGNMENT), for a service this trait fully
     * controls - so a specialization requirement can be attached to it
     * before the test runs. Mirrors CreatesBookingFixtures::successfulPayment()
     * exactly, except the service is created (and optionally linked to a
     * specialization) here instead of via createPricedCartService() alone,
     * since successfulPayment() never exposes the service it built.
     *
     * @param  array<string, mixed>  $overrides  with_specialization (bool, default true),
     *                                           specialization_id (int, reuse an existing one), slot (array, forwarded to createAppointmentSlot)
     * @return array{customer: array{user_uuid: string, access_token: string}, service: array{uuid: string, slug: string}, specialization_id: int|null, payment: object, booking: object, item: object}
     */
    private function bookingWithAssignableItem(array $overrides = []): array
    {
        $service = $this->createPricedCartService();

        $specializationId = null;

        if ($overrides['with_specialization'] ?? true) {
            $specializationId = $overrides['specialization_id'] ?? $this->createSpecialization();
            $this->linkServiceSpecialization($service['uuid'], $specializationId);
        }

        $customer = $this->createAuthenticatedCartCustomer();

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'quantity' => 1,
        ])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $slot = $this->createAppointmentSlot($overrides['slot'] ?? []);
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $createResponse = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $paymentRow = $this->paymentRow($createResponse->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $paymentRow->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $paymentRow->requested_amount,
        ]));

        $payment = $this->paymentRow(UuidBinary::toString($paymentRow->id));
        $booking = $this->bookingRowForPayment($payment);
        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        return [
            'customer' => $customer,
            'service' => $service,
            'specialization_id' => $specializationId,
            'payment' => $payment,
            'booking' => $booking,
            'item' => $item,
        ];
    }

    private function assignmentRow(string $assignmentUuid): ?object
    {
        return DB::table('technician_assignments')->where('id', UuidBinary::toBinary($assignmentUuid))->first();
    }

    private function activeAssignmentForItem(object $item): ?object
    {
        return DB::table('technician_assignments')->where('booking_item_id', $item->id)->whereNull('released_at')->first();
    }
}
