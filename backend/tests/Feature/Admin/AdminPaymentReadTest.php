<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B5 - read-only global Admin Payment visibility
 * (App\Actions\Admin\Payment\AdminListPaymentsAction/AdminGetPaymentAction /
 * App\Support\Admin\AdminPaymentPresenter). Mirrors AdminBookingReadTest's
 * structure/conventions exactly. No mutation endpoint exists for this
 * module - every test here is read-only.
 */
class AdminPaymentReadTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_admin_can_list_payments(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();

        $response = $this->getJson('/api/v1/admin/payments', $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true]);
        $uuids = collect($response->json('data.payments'))->pluck('uuid')->all();
        $this->assertContains(UuidBinary::toString($fixture['payment']->id), $uuids);
    }

    public function test_super_admin_can_list_payments(): void
    {
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);
        $this->successfulPayment();

        $this->getJson('/api/v1/admin/payments', $this->bearer($admin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_customer_cannot_list_admin_payments(): void
    {
        $fixture = $this->successfulPayment();

        $this->getJson('/api/v1/admin/payments', $this->bearer($fixture['customer']['access_token']))
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_list_admin_payments(): void
    {
        $this->getJson('/api/v1/admin/payments')->assertStatus(401);
    }

    public function test_pagination_shape_is_present(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->successfulPayment();
        $this->successfulPayment();

        $response = $this->getJson('/api/v1/admin/payments?per_page=1&page=1', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertSame(1, count($response->json('data.payments')));
        $this->assertSame(1, $response->json('data.pagination.per_page'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.total'));
    }

    public function test_status_filter_only_returns_matching_payments(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();

        $matching = $this->getJson('/api/v1/admin/payments?status=SUCCESSFUL', $this->bearer($admin['access_token']))
            ->assertStatus(200);
        $this->assertContains(
            UuidBinary::toString($fixture['payment']->id),
            collect($matching->json('data.payments'))->pluck('uuid')->all(),
        );

        $nonMatching = $this->getJson('/api/v1/admin/payments?status=FAILED', $this->bearer($admin['access_token']))
            ->assertStatus(200);
        $this->assertNotContains(
            UuidBinary::toString($fixture['payment']->id),
            collect($nonMatching->json('data.payments'))->pluck('uuid')->all(),
        );
    }

    public function test_checkout_reference_filter_returns_exactly_that_payment(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();

        $response = $this->getJson(
            '/api/v1/admin/payments?checkout_reference='.$fixture['payment']->checkout_reference,
            $this->bearer($admin['access_token']),
        );

        $uuids = collect($response->json('data.payments'))->pluck('uuid')->all();
        $this->assertSame([UuidBinary::toString($fixture['payment']->id)], $uuids);
    }

    public function test_customer_uuid_filter_scopes_to_that_customer_only(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixtureA = $this->successfulPayment();
        $fixtureB = $this->successfulPayment();

        $response = $this->getJson(
            '/api/v1/admin/payments?customer_uuid='.$fixtureA['customer']['user_uuid'],
            $this->bearer($admin['access_token']),
        );

        $uuids = collect($response->json('data.payments'))->pluck('uuid')->all();
        $this->assertContains(UuidBinary::toString($fixtureA['payment']->id), $uuids);
        $this->assertNotContains(UuidBinary::toString($fixtureB['payment']->id), $uuids);
    }

    public function test_admin_can_view_payment_detail_for_any_customer(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $response = $this->getJson(
            '/api/v1/admin/payments/'.UuidBinary::toString($fixture['payment']->id),
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $data = $response->json('data.payment');
        $this->assertSame('SUCCESSFUL', $data['status']);
        $this->assertSame($fixture['customer']['user_uuid'], $data['customer']['uuid']);
        $this->assertSame(UuidBinary::toString($booking->id), $data['booking']['uuid']);
        $this->assertIsArray($data['recent_webhook_events']);
    }

    public function test_malformed_payment_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/payments/not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_unknown_payment_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/payments/'.UuidBinary::generate(), $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_customer_cannot_view_admin_payment_detail(): void
    {
        $fixture = $this->successfulPayment();

        $this->getJson(
            '/api/v1/admin/payments/'.UuidBinary::toString($fixture['payment']->id),
            $this->bearer($fixture['customer']['access_token']),
        )->assertStatus(401);
    }

    public function test_payment_detail_never_exposes_secrets_or_raw_snapshot(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();

        $response = $this->getJson(
            '/api/v1/admin/payments/'.UuidBinary::toString($fixture['payment']->id),
            $this->bearer($admin['access_token']),
        );

        $json = json_encode($response->json());

        foreach ([
            'checkout_snapshot',
            'checkout_snapshot_hash',
            'idempotency_key',
            'client_secret',
            'publishable_key',
        ] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $json, "Response must never contain {$forbiddenKey}.");
        }
    }

    public function test_existing_customer_payment_endpoint_remains_ownership_scoped(): void
    {
        $fixtureA = $this->successfulPayment();
        $fixtureB = $this->successfulPayment();

        $this->getJson(
            '/api/v1/payments/'.UuidBinary::toString($fixtureB['payment']->id),
            ['Authorization' => 'Bearer '.$fixtureA['customer']['access_token']],
        )->assertStatus(404);
    }
}
