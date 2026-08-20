<?php

namespace Tests\Feature\Contract;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

class ContractRequestTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_customer_can_request_a_contract_for_one_service(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $response = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ]);

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertSame('REQUESTED', $response->json('data.contract.status'));
        $this->assertMatchesRegularExpression('/^CTR-/', $response->json('data.contract.contract_number'));
        $this->assertSame([], $response->json('data.contract.covered_services'));
    }

    public function test_customer_can_request_a_contract_for_multiple_services(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $serviceA = $this->createSubscriptionEligibleService();
        $serviceB = $this->createSubscriptionEligibleService();

        $response = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$serviceA['uuid'], $serviceB['uuid']],
        ]);

        $response->assertStatus(201);
        $this->assertFalse($response->json('data.contract.requested_all_services'));
    }

    public function test_customer_can_request_all_eligible_services(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $this->createSubscriptionEligibleService();
        $this->createSubscriptionEligibleService();

        $response = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => true,
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.contract.requested_all_services'));
    }

    public function test_request_all_services_rejected_when_none_eligible(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        // No SUBSCRIPTION-eligible service exists in this test's fixture DB.

        $response = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => true,
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_invalid_service_uuid_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        // A CART_ELIGIBLE-only service is not SUBSCRIPTION-eligible.
        $nonContractService = $this->createCartService();

        $response = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$nonContractService['uuid']],
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_unknown_service_uuid_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $response = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [(string) Str::uuid()],
        ]);

        $response->assertStatus(422);
    }

    public function test_request_requires_a_property_the_customer_owns(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($owner['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $stranger = $this->createAuthenticatedCartCustomer();

        $response = $this->requestContract($stranger['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ]);

        $response->assertStatus(404);
    }

    public function test_request_rejects_archived_property(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $this->deletePropertyHttp($customer['access_token'], $property['uuid'])->assertStatus(200);
        $service = $this->createSubscriptionEligibleService();

        $response = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ]);

        $response->assertStatus(409);
    }

    public function test_customer_cannot_choose_status_price_or_contract_number(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $response = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
            'status' => 'ACTIVE',
            'contract_number' => 'CTR-HACKED',
            'quoted_amount' => '1.000000',
        ]);

        $response->assertStatus(201);
        $this->assertSame('REQUESTED', $response->json('data.contract.status'));
        $this->assertNotSame('CTR-HACKED', $response->json('data.contract.contract_number'));
        $this->assertNull($response->json('data.contract.quoted_amount'));
    }

    public function test_customer_can_list_own_contracts(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $response = $this->listContractsHttp($customer['access_token']);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.contracts'));
    }


    public function test_customer_contract_detail_never_exposes_internal_or_provider_fields(): void
    {
        $ctx = $this->activeContractWithItem();

        $contractUuid = \App\Support\Uuid\UuidBinary::toString(
            $ctx['contract']->id
        );

        $response = $this->getContractHttp(
            $ctx['customer']['access_token'],
            $contractUuid
        );

        $response->assertStatus(200);

        $contract = $response->json('data.contract');

        $this->assertIsArray($contract);

        foreach ([
            'customer_user_id',
            'customer',
            'internal_note',
            'agreement_hash',
            'requested_service_ids',
            'requested_service_uuids',
            'accepted_by_user_id',
            'accepted_by_user_uuid',
            'status_history',
        ] as $forbiddenKey) {
            $this->assertArrayNotHasKey(
                $forbiddenKey,
                $contract,
                "Customer Contract response leaked forbidden field: {$forbiddenKey}"
            );
        }

        $billing = $contract['billing'] ?? null;

        if ($billing !== null) {
            foreach ([
                'uuid',
                'stripe_customer_id',
                'stripe_subscription_id',
                'stripe_price_id',
                'stripe_product_id',
                'stripe_checkout_session_id',
                'stripe_checkout_url',
                'checkout_session_id',
                'checkout_url',
                'past_due_since',
                'cancelled_at',
                'billing_suspended_at',
                'provider_cancellation_requested_at',
                'provider_cancellation_last_attempt_at',
                'provider_cancellation_attempt_count',
            ] as $forbiddenKey) {
                $this->assertArrayNotHasKey(
                    $forbiddenKey,
                    $billing,
                    "Customer Contract billing response leaked provider/internal field: {$forbiddenKey}"
                );
            }
        }

        $json = strtolower($response->getContent());

        foreach ([
            'agreement_hash',
            'internal_note',
            'stripe_customer_id',
            'stripe_subscription_id',
            'stripe_price_id',
            'stripe_product_id',
            'stripe_checkout_session_id',
            'stripe_checkout_url',
        ] as $forbiddenText) {
            $this->assertStringNotContainsString(
                $forbiddenText,
                $json
            );
        }

        $this->assertTrue(
            mb_check_encoding($response->getContent(), 'UTF-8'),
            'Customer Contract response must remain valid UTF-8 with no raw binary id leakage.'
        );
    }

    public function test_foreign_customer_cannot_read_another_customers_contract(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($owner['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($owner['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $stranger = $this->createAuthenticatedCartCustomer();

        $response = $this->getContractHttp($stranger['access_token'], $created->json('data.contract.uuid'));

        $response->assertStatus(404);
    }

    public function test_foreign_customer_cannot_accept_another_customers_contract(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($owner['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($owner['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $stranger = $this->createAuthenticatedCartCustomer();

        $response = $this->acceptContractHttp($stranger['access_token'], $created->json('data.contract.uuid'));

        $response->assertStatus(404);
    }

    public function test_get_with_malformed_uuid_returns_404(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->getContractHttp($customer['access_token'], 'not-a-uuid');

        $response->assertStatus(404);
    }
}
