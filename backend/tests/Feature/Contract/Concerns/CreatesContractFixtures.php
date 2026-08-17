<?php

namespace Tests\Feature\Contract\Concerns;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Property\Concerns\CreatesPropertyFixtures;

/**
 * Service-Contract-specific fixture builders (BLUE V1 Phase 10), layered on
 * top of CreatesAdminFixtures (customer/admin/technician builders) and
 * CreatesPropertyFixtures (customer_properties builder) - a Contract always
 * belongs to a customer's Property, and Admin approval/acceptance/booking
 * tests need the full authenticated Admin + Customer session chain.
 */
trait CreatesContractFixtures
{
    use CreatesAdminFixtures;
    use CreatesPropertyFixtures;

    private static int $contractFixtureSequence = 0;

    /**
     * A service with the SUBSCRIPTION capability (BLUE V1's existing
     * capability flag reused as CONTRACT-eligibility) and a PUBLISHED,
     * unconditional pricing scheme - the shape App\Actions\Contract\
     * CreateContractBookingAction needs to resolve a pricing_scheme_version_id
     * for the Booking Item it writes.
     *
     * @return array{uuid: string, slug: string}
     */
    private function createSubscriptionEligibleService(array $overrides = []): array
    {
        $service = $this->createCartService(null, ['cart_eligible' => false] + $overrides);
        $this->makeSubscriptionEligible($service['uuid']);
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme);

        return $service;
    }

    private function makeSubscriptionEligible(string $serviceUuid): void
    {
        DB::table('service_capabilities')->insert([
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'capability_type_id' => (int) DB::table('service_capability_types')->where('code', 'SUBSCRIPTION')->value('id'),
            'created_at' => now(),
        ]);
    }

    private function requestContract(string $accessToken, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/contracts/requests', $payload, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function listContractsHttp(string $accessToken): TestResponse
    {
        return $this->getJson('/api/v1/contracts', ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function getContractHttp(string $accessToken, string $contractUuid): TestResponse
    {
        return $this->getJson('/api/v1/contracts/'.$contractUuid, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function acceptContractHttp(string $accessToken, string $contractUuid): TestResponse
    {
        return $this->postJson('/api/v1/contracts/'.$contractUuid.'/accept', [], ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function bookContractService(string $accessToken, string $contractUuid, string $contractItemUuid, string $slotUuid): TestResponse
    {
        return $this->postJson(
            '/api/v1/contracts/'.$contractUuid.'/services/'.$contractItemUuid.'/book',
            ['appointment_slot_uuid' => $slotUuid],
            ['Authorization' => 'Bearer '.$accessToken]
        );
    }

    private function adminListContracts(string $accessToken, array $query = []): TestResponse
    {
        $suffix = $query === [] ? '' : ('?'.http_build_query($query));

        return $this->getJson('/api/v1/admin/contracts'.$suffix, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function adminGetContract(string $accessToken, string $contractUuid): TestResponse
    {
        return $this->getJson('/api/v1/admin/contracts/'.$contractUuid, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function adminApproveContract(string $accessToken, string $contractUuid, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/admin/contracts/'.$contractUuid.'/approve', $payload, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function adminSendContractForAcceptance(string $accessToken, string $contractUuid): TestResponse
    {
        return $this->postJson('/api/v1/admin/contracts/'.$contractUuid.'/send-for-acceptance', [], ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function adminSuspendContract(string $accessToken, string $contractUuid, array $payload = []): TestResponse
    {
        return $this->postJson('/api/v1/admin/contracts/'.$contractUuid.'/suspend', $payload, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function adminCancelContract(string $accessToken, string $contractUuid, array $payload = []): TestResponse
    {
        return $this->postJson('/api/v1/admin/contracts/'.$contractUuid.'/cancel', $payload, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function contractRow(string $contractUuid): ?object
    {
        return DB::table('service_contracts')->where('id', UuidBinary::toBinary($contractUuid))->first();
    }

    private function contractItemRows(string $contractUuid): \Illuminate\Support\Collection
    {
        return DB::table('service_contract_items')->where('service_contract_id', UuidBinary::toBinary($contractUuid))->get();
    }

    private function approveContractPayload(string $serviceUuid, array $overrides = []): array
    {
        $now = now();

        return array_merge([
            'starts_at' => $now->copy()->subDay()->toIso8601String(),
            'ends_at' => $now->copy()->addDays(30)->toIso8601String(),
            'services' => [
                [
                    'service_uuid' => $serviceUuid,
                    'entitlement_mode' => 'LIMITED_VISITS',
                    'included_visits' => 2,
                ],
            ],
        ], $overrides);
    }

    /**
     * Builds a full ACTIVE Service Contract with one LIMITED_VISITS covered
     * service, ready for CreateContractBookingAction to consume - request ->
     * approve -> send-for-acceptance -> accept, exactly the real Admin/
     * Customer flow, never a shortcut direct-DB insert of the final ACTIVE
     * row, so every layer's own transition logic is exercised by every test
     * that reuses this builder.
     *
     * @return array{customer: array{user_uuid: string, access_token: string}, admin: array{user_uuid: string, access_token: string}, property_uuid: string, service: array{uuid: string, slug: string}, contract: object, item: object}
     */
    private function activeContractWithItem(array $overrides = []): array
    {
        self::$contractFixtureSequence++;

        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $overrides['service'] ?? $this->createSubscriptionEligibleService();

        $requestResponse = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $contractUuid = $requestResponse->json('data.contract.uuid');

        $admin = $this->createAndLoginAdmin();

        $this->adminApproveContract(
            $admin['access_token'],
            $contractUuid,
            $this->approveContractPayload($service['uuid'], $overrides['approve'] ?? [])
        )->assertStatus(200);

        $this->adminSendContractForAcceptance($admin['access_token'], $contractUuid)->assertStatus(200);

        $this->acceptContractHttp($customer['access_token'], $contractUuid)->assertStatus(200);

        $contract = $this->contractRow($contractUuid);
        $item = $this->contractItemRows($contractUuid)->first();

        return [
            'customer' => $customer,
            'admin' => $admin,
            'property_uuid' => $property['uuid'],
            'service' => $service,
            'contract' => $contract,
            'item' => $item,
        ];
    }
}
