<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B11 - Admin Ratings visibility (App\Actions\Admin\Rating\*
 * / App\Support\Admin\AdminRatingPresenter). Entirely read-only: no
 * customer-facing rating-creation endpoint exists anywhere in this
 * codebase yet, so fixtures here insert `ratings` rows directly - the same
 * "mint the state directly when no real endpoint produces it yet"
 * convention already used for Support Requests (B7) and Pricing (B9).
 */
class AdminRatingTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    /**
     * @return array{booking: object, customer: array{user_uuid: string, access_token: string}}
     */
    private function completedBookingWithRating(int $ratingValue = 5, ?string $comment = 'Great service.'): array
    {
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        DB::table('bookings')->where('id', $booking->id)->update([
            'status_id' => DB::table('booking_statuses')->where('code', 'COMPLETED')->value('id'),
            'completed_at' => $booking->created_at,
        ]);

        DB::table('ratings')->insert([
            'booking_id' => $booking->id,
            'rating_value' => $ratingValue,
            'comment' => $comment,
            'created_at' => $booking->created_at,
        ]);

        return ['booking' => $booking, 'customer' => $fixture['customer']];
    }

    // -----------------------------------------------------------------
    // READ
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_cannot_list_ratings(): void
    {
        $this->getJson('/api/v1/admin/ratings')->assertStatus(401);
    }

    public function test_customer_cannot_list_ratings(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getJson('/api/v1/admin/ratings', $this->bearer($customer['access_token']))->assertStatus(401);
    }

    public function test_admin_can_list_ratings(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->completedBookingWithRating();

        $response = $this->getJson('/api/v1/admin/ratings', $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true]);
        $uuids = collect($response->json('data.ratings'))->pluck('booking_uuid')->all();
        $this->assertContains(UuidBinary::toString($fixture['booking']->id), $uuids);
    }

    public function test_super_admin_can_list_ratings(): void
    {
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $this->getJson('/api/v1/admin/ratings', $this->bearer($admin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_admin_without_ratings_capability_is_denied(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'ratings.view')->value('id');

        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/ratings', $this->bearer($admin['access_token']))->assertStatus(403);
    }

    public function test_ratings_list_pagination_shape_is_present(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->completedBookingWithRating();
        $this->completedBookingWithRating();

        $response = $this->getJson('/api/v1/admin/ratings?per_page=1&page=1', $this->bearer($admin['access_token']));

        $this->assertSame(1, count($response->json('data.ratings')));
        $this->assertSame(1, $response->json('data.pagination.per_page'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.total'));
    }

    public function test_rating_value_filter_matches_exactly(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $five = $this->completedBookingWithRating(5);
        $two = $this->completedBookingWithRating(2);

        $response = $this->getJson('/api/v1/admin/ratings?rating_value=5', $this->bearer($admin['access_token']));

        $uuids = collect($response->json('data.ratings'))->pluck('booking_uuid')->all();
        $this->assertContains(UuidBinary::toString($five['booking']->id), $uuids);
        $this->assertNotContains(UuidBinary::toString($two['booking']->id), $uuids);
    }

    public function test_max_rating_filter_surfaces_low_ratings_only(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $five = $this->completedBookingWithRating(5);
        $two = $this->completedBookingWithRating(2);

        $response = $this->getJson('/api/v1/admin/ratings?max_rating=2', $this->bearer($admin['access_token']));

        $uuids = collect($response->json('data.ratings'))->pluck('booking_uuid')->all();
        $this->assertContains(UuidBinary::toString($two['booking']->id), $uuids);
        $this->assertNotContains(UuidBinary::toString($five['booking']->id), $uuids);
    }

    public function test_customer_uuid_filter_scopes_to_that_customer_only(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixtureA = $this->completedBookingWithRating();
        $fixtureB = $this->completedBookingWithRating();

        $response = $this->getJson(
            '/api/v1/admin/ratings?customer_uuid='.$fixtureA['customer']['user_uuid'],
            $this->bearer($admin['access_token']),
        );

        $uuids = collect($response->json('data.ratings'))->pluck('booking_uuid')->all();
        $this->assertContains(UuidBinary::toString($fixtureA['booking']->id), $uuids);
        $this->assertNotContains(UuidBinary::toString($fixtureB['booking']->id), $uuids);
    }

    public function test_booking_uuid_filter_matches_exactly(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixtureA = $this->completedBookingWithRating();
        $fixtureB = $this->completedBookingWithRating();

        $response = $this->getJson(
            '/api/v1/admin/ratings?booking_uuid='.UuidBinary::toString($fixtureA['booking']->id),
            $this->bearer($admin['access_token']),
        );

        $uuids = collect($response->json('data.ratings'))->pluck('booking_uuid')->all();
        $this->assertSame([UuidBinary::toString($fixtureA['booking']->id)], $uuids);
        $this->assertNotContains(UuidBinary::toString($fixtureB['booking']->id), $uuids);
    }

    public function test_malformed_uuid_filter_is_rejected_with_validation_error(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->completedBookingWithRating();

        $response = $this->getJson('/api/v1/admin/ratings?booking_uuid=not-a-uuid', $this->bearer($admin['access_token']));

        $response->assertStatus(422);
    }

    public function test_admin_can_view_rating_detail(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->completedBookingWithRating(4, 'Technician was on time.');

        $response = $this->getJson(
            '/api/v1/admin/ratings/'.UuidBinary::toString($fixture['booking']->id),
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200);
        $data = $response->json('data.rating');
        $this->assertSame(4, $data['rating_value']);
        $this->assertSame('Technician was on time.', $data['comment']);
        $this->assertSame('COMPLETED', $data['booking_status']);
        $this->assertSame($fixture['customer']['user_uuid'], $data['customer']['uuid']);
        $this->assertNotEmpty($data['services']);
    }

    public function test_malformed_booking_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/ratings/not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_unknown_booking_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/ratings/'.UuidBinary::generate(), $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_booking_without_a_rating_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->getJson(
            '/api/v1/admin/ratings/'.UuidBinary::toString($booking->id),
            $this->bearer($admin['access_token']),
        )->assertStatus(404);
    }

    public function test_customer_cannot_view_rating_detail(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $fixture = $this->completedBookingWithRating();

        $this->getJson(
            '/api/v1/admin/ratings/'.UuidBinary::toString($fixture['booking']->id),
            $this->bearer($customer['access_token']),
        )->assertStatus(401);
    }

    public function test_rating_responses_never_expose_security_material_or_raw_binary_ids(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->completedBookingWithRating();

        $response = $this->getJson(
            '/api/v1/admin/ratings/'.UuidBinary::toString($fixture['booking']->id),
            $this->bearer($admin['access_token']),
        );

        $json = json_encode($response->json());

        foreach (['password_hash', 'refresh_token_hash', 'client_secret', 'checkout_snapshot'] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $json, "Response must never contain {$forbiddenKey}.");
        }

        $this->assertStringNotContainsString(bin2hex($fixture['booking']->id), $json);
    }
}
