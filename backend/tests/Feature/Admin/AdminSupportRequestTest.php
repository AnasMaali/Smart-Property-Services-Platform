<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B7 - Admin Support Requests/Messages
 * (App\Actions\Admin\Support\AdminListSupportRequestsAction/
 * AdminGetSupportRequestAction/AdminSendSupportMessageAction /
 * App\Support\Admin\AdminSupportRequestPresenter).
 *
 * No customer-facing Support implementation exists yet (only the schema is
 * provisioned - see docs/api-contracts/admin-operations-v1.md "Support"),
 * so fixtures here insert `support_requests`/`support_messages` rows
 * directly rather than driving a real HTTP flow that does not exist - the
 * same "mint the state directly when no real endpoint produces it yet"
 * convention already used elsewhere in this test suite (e.g. Tests\Support\
 * AuthenticatesAdminsForTests).
 */
class AdminSupportRequestTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    /**
     * @return array{uuid: string, request_number: string}
     */
    private function createSupportRequest(string $customerUuid, array $overrides = []): array
    {
        self::$sequence++;
        $requestUuid = UuidBinary::generate();
        $requestNumber = 'SUP-QA-'.str_pad((string) self::$sequence, 6, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('support_requests')->insert(array_merge([
            'id' => UuidBinary::toBinary($requestUuid),
            'request_number' => $requestNumber,
            'customer_user_id' => UuidBinary::toBinary($customerUuid),
            'booking_id' => null,
            'status_id' => $this->supportStatusId('OPEN'),
            'assigned_admin_user_id' => null,
            'subject' => 'QA support subject '.self::$sequence,
            'status_changed_at' => $now,
            'resolved_at' => null,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return ['uuid' => $requestUuid, 'request_number' => $requestNumber];
    }

    private function createSupportMessage(string $requestUuid, string $senderUuid, string $body, ?Carbon $at = null): string
    {
        $messageUuid = UuidBinary::generate();
        $at ??= now();

        DB::table('support_messages')->insert([
            'id' => UuidBinary::toBinary($messageUuid),
            'support_request_id' => UuidBinary::toBinary($requestUuid),
            'sender_user_id' => UuidBinary::toBinary($senderUuid),
            'message_body' => $body,
            'created_at' => $at,
        ]);

        return $messageUuid;
    }

    private function supportStatusId(string $code): int
    {
        return (int) DB::table('support_request_statuses')->where('code', $code)->value('id');
    }

    // -----------------------------------------------------------------
    // READ
    // -----------------------------------------------------------------

    public function test_admin_can_list_support_requests(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->getJson('/api/v1/admin/support-requests', $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true]);
        $uuids = collect($response->json('data.support_requests'))->pluck('uuid')->all();
        $this->assertContains($support['uuid'], $uuids);
    }

    public function test_super_admin_can_list_support_requests(): void
    {
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $this->createSupportRequest($customer['user_uuid']);

        $this->getJson('/api/v1/admin/support-requests', $this->bearer($admin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_customer_cannot_list_admin_support_requests(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getJson('/api/v1/admin/support-requests', $this->bearer($customer['access_token']))
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_list_support_requests(): void
    {
        $this->getJson('/api/v1/admin/support-requests')->assertStatus(401);
    }

    public function test_pagination_shape_is_present(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $this->createSupportRequest($customer['user_uuid']);
        $this->createSupportRequest($customer['user_uuid']);

        $response = $this->getJson('/api/v1/admin/support-requests?per_page=1&page=1', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertSame(1, count($response->json('data.support_requests')));
        $this->assertSame(1, $response->json('data.pagination.per_page'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.total'));
    }

    public function test_status_filter_only_returns_matching_requests(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $open = $this->createSupportRequest($customer['user_uuid']);
        $closed = $this->createSupportRequest($customer['user_uuid'], ['status_id' => $this->supportStatusId('CLOSED'), 'closed_at' => now()]);

        $matching = $this->getJson('/api/v1/admin/support-requests?status=OPEN', $this->bearer($admin['access_token']))->assertStatus(200);
        $uuids = collect($matching->json('data.support_requests'))->pluck('uuid')->all();
        $this->assertContains($open['uuid'], $uuids);
        $this->assertNotContains($closed['uuid'], $uuids);
    }

    public function test_customer_uuid_filter_scopes_to_that_customer_only(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customerA = $this->createAuthenticatedCartCustomer();
        $customerB = $this->createAuthenticatedCartCustomer();
        $supportA = $this->createSupportRequest($customerA['user_uuid']);
        $supportB = $this->createSupportRequest($customerB['user_uuid']);

        $response = $this->getJson(
            '/api/v1/admin/support-requests?customer_uuid='.$customerA['user_uuid'],
            $this->bearer($admin['access_token']),
        );

        $uuids = collect($response->json('data.support_requests'))->pluck('uuid')->all();
        $this->assertContains($supportA['uuid'], $uuids);
        $this->assertNotContains($supportB['uuid'], $uuids);
    }

    public function test_search_filter_matches_subject(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid'], ['subject' => 'Booking rescheduling problem']);

        $response = $this->getJson(
            '/api/v1/admin/support-requests?search=rescheduling',
            $this->bearer($admin['access_token']),
        );

        $this->assertContains($support['uuid'], collect($response->json('data.support_requests'))->pluck('uuid')->all());
    }

    public function test_unassigned_filter_excludes_assigned_requests(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $unassigned = $this->createSupportRequest($customer['user_uuid']);
        $assigned = $this->createSupportRequest($customer['user_uuid'], ['assigned_admin_user_id' => UuidBinary::toBinary($admin['user_uuid'])]);

        $response = $this->getJson('/api/v1/admin/support-requests?unassigned=1', $this->bearer($admin['access_token']));

        $uuids = collect($response->json('data.support_requests'))->pluck('uuid')->all();
        $this->assertContains($unassigned['uuid'], $uuids);
        $this->assertNotContains($assigned['uuid'], $uuids);
    }

    public function test_admin_can_view_support_request_detail_with_customer_and_booking(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $support = $this->createSupportRequest($fixture['customer']['user_uuid'], ['booking_id' => $booking->id]);

        $response = $this->getJson(
            '/api/v1/admin/support-requests/'.$support['uuid'],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $data = $response->json('data.support_request');
        $this->assertSame($fixture['customer']['user_uuid'], $data['customer']['uuid']);
        $this->assertSame(UuidBinary::toString($booking->id), $data['booking']['uuid']);
        $this->assertSame('OPEN', $data['status']);
    }

    public function test_support_request_detail_without_booking_works(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->getJson('/api/v1/admin/support-requests/'.$support['uuid'], $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertNull($response->json('data.support_request.booking'));
    }

    public function test_messages_are_returned_in_chronological_order(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->createSupportMessage($support['uuid'], $customer['user_uuid'], 'First message', Carbon::now()->subMinutes(10));
        $this->createSupportMessage($support['uuid'], $admin['user_uuid'], 'Second message', Carbon::now()->subMinutes(5));
        $this->createSupportMessage($support['uuid'], $customer['user_uuid'], 'Third message', Carbon::now());

        $response = $this->getJson('/api/v1/admin/support-requests/'.$support['uuid'], $this->bearer($admin['access_token']));

        $bodies = collect($response->json('data.support_request.messages'))->pluck('message_body')->all();
        $this->assertSame(['First message', 'Second message', 'Third message'], $bodies);
    }

    public function test_sender_identity_is_presented_safely(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->createSupportMessage($support['uuid'], $customer['user_uuid'], 'From the customer', Carbon::now()->subMinute());
        $this->createSupportMessage($support['uuid'], $admin['user_uuid'], 'From the admin', Carbon::now());

        $response = $this->getJson('/api/v1/admin/support-requests/'.$support['uuid'], $this->bearer($admin['access_token']));
        $messages = $response->json('data.support_request.messages');

        $this->assertSame('CUSTOMER', $messages[0]['sender']['type']);
        $this->assertSame($customer['user_uuid'], $messages[0]['sender']['uuid']);
        $this->assertSame('ADMIN', $messages[1]['sender']['type']);
        $this->assertSame($admin['user_uuid'], $messages[1]['sender']['uuid']);
    }

    public function test_malformed_support_request_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/support-requests/not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_unknown_support_request_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/support-requests/'.UuidBinary::generate(), $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_customer_cannot_view_admin_support_request_detail(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->getJson(
            '/api/v1/admin/support-requests/'.$support['uuid'],
            $this->bearer($customer['access_token']),
        )->assertStatus(401);
    }

    public function test_support_request_detail_never_exposes_security_material(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);
        $this->createSupportMessage($support['uuid'], $customer['user_uuid'], 'Hello, need help.');

        $response = $this->getJson('/api/v1/admin/support-requests/'.$support['uuid'], $this->bearer($admin['access_token']));
        $json = json_encode($response->json());

        foreach (['password_hash', 'refresh_token_hash', 'client_secret'] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $json, "Response must never contain {$forbiddenKey}.");
        }
    }

    // -----------------------------------------------------------------
    // WRITE - Admin reply message
    // -----------------------------------------------------------------

    public function test_admin_can_post_a_reply_message(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/messages',
            ['message_body' => 'We are looking into this now.'],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
        $messages = $response->json('data.support_request.messages');
        $this->assertSame('We are looking into this now.', end($messages)['message_body']);
    }

    public function test_message_is_stored_with_the_authenticated_admin_as_sender(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/messages',
            ['message_body' => 'Reply body.'],
            $this->bearer($admin['access_token']),
        )->assertStatus(201);

        $stored = DB::table('support_messages')->where('support_request_id', UuidBinary::toBinary($support['uuid']))->first();
        $this->assertSame(UuidBinary::toBinary($admin['user_uuid']), $stored->sender_user_id);
    }

    public function test_message_send_ignores_spoofed_sender_fields_in_the_body(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $otherAdmin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/messages',
            ['message_body' => 'Reply body.', 'sender_user_id' => $otherAdmin['user_uuid'], 'support_request_id' => UuidBinary::generate()],
            $this->bearer($admin['access_token']),
        )->assertStatus(201);

        $stored = DB::table('support_messages')->where('support_request_id', UuidBinary::toBinary($support['uuid']))->first();
        $this->assertSame(UuidBinary::toBinary($admin['user_uuid']), $stored->sender_user_id);
    }

    public function test_blank_message_body_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/messages',
            ['message_body' => ''],
            $this->bearer($admin['access_token']),
        )->assertStatus(422);

        $this->assertSame(0, DB::table('support_messages')->where('support_request_id', UuidBinary::toBinary($support['uuid']))->count());
    }

    public function test_too_long_message_body_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/messages',
            ['message_body' => str_repeat('a', 5001)],
            $this->bearer($admin['access_token']),
        )->assertStatus(422);
    }

    public function test_failed_send_creates_no_partial_state(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.UuidBinary::generate().'/messages',
            ['message_body' => 'Hello'],
            $this->bearer($admin['access_token']),
        )->assertStatus(404);

        $this->assertSame(0, DB::table('support_messages')->count());
    }

    public function test_message_send_response_never_exposes_unsafe_fields(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/messages',
            ['message_body' => 'Reply body.'],
            $this->bearer($admin['access_token']),
        );

        $json = json_encode($response->json());
        $this->assertStringNotContainsString('password_hash', $json);
    }

    public function test_customer_cannot_use_the_admin_message_endpoint(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/messages',
            ['message_body' => 'Should not work.'],
            $this->bearer($customer['access_token']),
        )->assertStatus(401);
    }

    public function test_reply_message_writes_an_audit_row_without_the_message_text(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/messages',
            ['message_body' => 'Secret detail that must not leak into audit logs.'],
            $this->bearer($admin['access_token']),
        )->assertStatus(201);

        $audit = DB::table('admin_audit_logs')
            ->where('entity_identifier', $support['uuid'])
            ->where('action_code', 'SUPPORT_MESSAGE_SENT')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(UuidBinary::toBinary($admin['user_uuid']), $audit->admin_user_id);
        $this->assertStringNotContainsString('Secret detail', (string) $audit->new_values);
    }

    public function test_reply_message_does_not_change_the_request_status(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/messages',
            ['message_body' => 'Reply body.'],
            $this->bearer($admin['access_token']),
        )->assertStatus(201);

        $this->assertSame(
            $this->supportStatusId('OPEN'),
            DB::table('support_requests')->where('id', UuidBinary::toBinary($support['uuid']))->value('status_id'),
        );
    }

    // -----------------------------------------------------------------
    // INTEGRITY
    // -----------------------------------------------------------------

    public function test_no_raw_binary_identifiers_are_exposed(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->getJson('/api/v1/admin/support-requests/'.$support['uuid'], $this->bearer($admin['access_token']));

        $this->assertSame($support['uuid'], $response->json('data.support_request.uuid'));
        $this->assertSame($customer['user_uuid'], $response->json('data.support_request.customer.uuid'));
    }

    // -----------------------------------------------------------------
    // WRITE - Status transitions
    // -----------------------------------------------------------------

    private function revokeSupportManage(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'support.manage')->value('id');

        DB::table('admin_role_permissions')
            ->where('role_id', $adminRoleId)
            ->where('permission_id', $permissionId)
            ->delete();
    }

    public function test_admin_can_transition_open_to_in_progress(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/status',
            ['status' => 'IN_PROGRESS'],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame('IN_PROGRESS', $response->json('data.support_request.status'));
    }

    public function test_admin_can_transition_open_directly_to_resolved(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/status',
            ['status' => 'RESOLVED'],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200);
        $this->assertSame('RESOLVED', $response->json('data.support_request.status'));
        $this->assertNotNull($response->json('data.support_request.resolved_at'));
    }

    public function test_admin_can_progress_through_the_full_lifecycle(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);
        $headers = $this->bearer($admin['access_token']);
        $url = '/api/v1/admin/support-requests/'.$support['uuid'].'/status';

        $this->postJson($url, ['status' => 'IN_PROGRESS'], $headers)->assertStatus(200);
        $this->postJson($url, ['status' => 'RESOLVED'], $headers)->assertStatus(200);

        $resolvedRow = DB::table('support_requests')->where('id', UuidBinary::toBinary($support['uuid']))->first();
        $this->assertNotNull($resolvedRow->resolved_at);
        $this->assertNull($resolvedRow->closed_at);

        $closeResponse = $this->postJson($url, ['status' => 'CLOSED'], $headers);
        $closeResponse->assertStatus(200);
        $this->assertSame('CLOSED', $closeResponse->json('data.support_request.status'));
        $this->assertNotNull($closeResponse->json('data.support_request.resolved_at'));
        $this->assertNotNull($closeResponse->json('data.support_request.closed_at'));
    }

    public function test_reopening_a_closed_request_clears_resolved_and_closed_timestamps(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $past = Carbon::now()->subHour();
        $support = $this->createSupportRequest($customer['user_uuid'], [
            'status_id' => $this->supportStatusId('CLOSED'),
            'created_at' => $past,
            'status_changed_at' => $past,
            'resolved_at' => $past,
            'closed_at' => $past,
        ]);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/status',
            ['status' => 'IN_PROGRESS'],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200);
        $this->assertSame('IN_PROGRESS', $response->json('data.support_request.status'));
        $this->assertNull($response->json('data.support_request.resolved_at'));
        $this->assertNull($response->json('data.support_request.closed_at'));
    }

    public function test_open_cannot_transition_directly_to_closed(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/status',
            ['status' => 'CLOSED'],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(409)->assertJson(['success' => false]);
        $this->assertSame('OPEN', $this->supportStatusCode($support['uuid']));
    }

    public function test_closed_cannot_reopen_directly_to_open(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid'], ['status_id' => $this->supportStatusId('CLOSED'), 'closed_at' => now()]);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/status',
            ['status' => 'OPEN'],
            $this->bearer($admin['access_token']),
        )->assertStatus(409);
    }

    public function test_setting_the_same_status_is_idempotent(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/status',
            ['status' => 'OPEN'],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true, 'message' => 'Support request is already in this status.']);
        $this->assertSame(0, DB::table('admin_audit_logs')->where('entity_identifier', $support['uuid'])->where('action_code', 'SUPPORT_REQUEST_STATUS_CHANGED')->count());
    }

    public function test_invalid_status_code_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/status',
            ['status' => 'NOT_A_REAL_STATUS'],
            $this->bearer($admin['access_token']),
        )->assertStatus(422);
    }

    public function test_status_change_on_nonexistent_request_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.UuidBinary::generate().'/status',
            ['status' => 'IN_PROGRESS'],
            $this->bearer($admin['access_token']),
        )->assertStatus(404);
    }

    public function test_status_change_writes_an_audit_row(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/status',
            ['status' => 'IN_PROGRESS'],
            $this->bearer($admin['access_token']),
        )->assertStatus(200);

        $audit = DB::table('admin_audit_logs')
            ->where('entity_identifier', $support['uuid'])
            ->where('action_code', 'SUPPORT_REQUEST_STATUS_CHANGED')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(UuidBinary::toBinary($admin['user_uuid']), $audit->admin_user_id);
        $this->assertStringContainsString('IN_PROGRESS', (string) $audit->new_values);
        $this->assertStringContainsString('OPEN', (string) $audit->old_values);
    }

    public function test_customer_cannot_change_support_request_status(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/status',
            ['status' => 'IN_PROGRESS'],
            $this->bearer($customer['access_token']),
        )->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_change_status(): void
    {
        $this->postJson(
            '/api/v1/admin/support-requests/'.UuidBinary::generate().'/status',
            ['status' => 'IN_PROGRESS'],
        )->assertStatus(401);
    }

    public function test_status_change_requires_support_manage_capability(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);
        $this->revokeSupportManage();

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/status',
            ['status' => 'IN_PROGRESS'],
            $this->bearer($admin['access_token']),
        )->assertStatus(403);
    }

    private function supportStatusCode(string $supportRequestUuid): string
    {
        return (string) DB::table('support_requests')
            ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
            ->where('support_requests.id', UuidBinary::toBinary($supportRequestUuid))
            ->value('support_request_statuses.code');
    }

    // -----------------------------------------------------------------
    // WRITE - Assignment
    // -----------------------------------------------------------------

    public function test_admin_can_assign_a_support_request(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $assignee = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/assign-admin',
            ['admin_uuid' => $assignee['user_uuid']],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame($assignee['user_uuid'], $response->json('data.support_request.assigned_admin.uuid'));
    }

    public function test_admin_can_reassign_to_a_different_admin(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $firstAssignee = $this->createAndLoginAdmin(['ADMIN']);
        $secondAssignee = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid'], ['assigned_admin_user_id' => UuidBinary::toBinary($firstAssignee['user_uuid'])]);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/assign-admin',
            ['admin_uuid' => $secondAssignee['user_uuid']],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true, 'message' => 'Support request reassigned successfully.']);
        $this->assertSame($secondAssignee['user_uuid'], $response->json('data.support_request.assigned_admin.uuid'));
    }

    public function test_admin_can_unassign_a_support_request(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $assignee = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid'], ['assigned_admin_user_id' => UuidBinary::toBinary($assignee['user_uuid'])]);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/unassign-admin',
            [],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertNull($response->json('data.support_request.assigned_admin'));
    }

    public function test_assigning_to_a_non_admin_user_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/assign-admin',
            ['admin_uuid' => $customer['user_uuid']],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(422);
        $this->assertNull(DB::table('support_requests')->where('id', UuidBinary::toBinary($support['uuid']))->value('assigned_admin_user_id'));
    }

    public function test_assigning_to_a_nonexistent_user_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/assign-admin',
            ['admin_uuid' => UuidBinary::generate()],
            $this->bearer($admin['access_token']),
        )->assertStatus(422);
    }

    public function test_malformed_admin_uuid_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/assign-admin',
            ['admin_uuid' => 'not-a-uuid'],
            $this->bearer($admin['access_token']),
        )->assertStatus(422);
    }

    public function test_assigning_the_same_admin_again_is_idempotent(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $assignee = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid'], ['assigned_admin_user_id' => UuidBinary::toBinary($assignee['user_uuid'])]);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/assign-admin',
            ['admin_uuid' => $assignee['user_uuid']],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true, 'message' => 'This admin is already assigned to this support request.']);
        $this->assertSame(0, DB::table('admin_audit_logs')->where('entity_identifier', $support['uuid'])->whereIn('action_code', ['SUPPORT_REQUEST_ASSIGNED', 'SUPPORT_REQUEST_REASSIGNED'])->count());
    }

    public function test_unassigning_an_already_unassigned_request_is_idempotent(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $response = $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/unassign-admin',
            [],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200)->assertJson(['success' => true, 'message' => 'This support request is already unassigned.']);
        $this->assertSame(0, DB::table('admin_audit_logs')->where('entity_identifier', $support['uuid'])->where('action_code', 'SUPPORT_REQUEST_UNASSIGNED')->count());
    }

    public function test_assign_on_nonexistent_request_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $assignee = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.UuidBinary::generate().'/assign-admin',
            ['admin_uuid' => $assignee['user_uuid']],
            $this->bearer($admin['access_token']),
        )->assertStatus(404);
    }

    public function test_unassign_on_nonexistent_request_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.UuidBinary::generate().'/unassign-admin',
            [],
            $this->bearer($admin['access_token']),
        )->assertStatus(404);
    }

    public function test_assignment_writes_an_audit_row(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $assignee = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/assign-admin',
            ['admin_uuid' => $assignee['user_uuid']],
            $this->bearer($admin['access_token']),
        )->assertStatus(200);

        $audit = DB::table('admin_audit_logs')
            ->where('entity_identifier', $support['uuid'])
            ->where('action_code', 'SUPPORT_REQUEST_ASSIGNED')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(UuidBinary::toBinary($admin['user_uuid']), $audit->admin_user_id);
        $this->assertStringContainsString($assignee['user_uuid'], (string) $audit->new_values);
    }

    public function test_unassignment_writes_an_audit_row(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $assignee = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid'], ['assigned_admin_user_id' => UuidBinary::toBinary($assignee['user_uuid'])]);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/unassign-admin',
            [],
            $this->bearer($admin['access_token']),
        )->assertStatus(200);

        $audit = DB::table('admin_audit_logs')
            ->where('entity_identifier', $support['uuid'])
            ->where('action_code', 'SUPPORT_REQUEST_UNASSIGNED')
            ->first();

        $this->assertNotNull($audit);
        $this->assertStringContainsString($assignee['user_uuid'], (string) $audit->old_values);
    }

    public function test_customer_cannot_assign_support_requests(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/assign-admin',
            ['admin_uuid' => $customer['user_uuid']],
            $this->bearer($customer['access_token']),
        )->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_unassign(): void
    {
        $this->postJson('/api/v1/admin/support-requests/'.UuidBinary::generate().'/unassign-admin')->assertStatus(401);
    }

    public function test_assignment_requires_support_manage_capability(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $assignee = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);
        $this->revokeSupportManage();

        $this->postJson(
            '/api/v1/admin/support-requests/'.$support['uuid'].'/assign-admin',
            ['admin_uuid' => $assignee['user_uuid']],
            $this->bearer($admin['access_token']),
        )->assertStatus(403);
    }

    public function test_status_and_assignment_responses_never_expose_unsafe_fields(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $assignee = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $support = $this->createSupportRequest($customer['user_uuid']);
        $headers = $this->bearer($admin['access_token']);

        $statusResponse = $this->postJson('/api/v1/admin/support-requests/'.$support['uuid'].'/status', ['status' => 'IN_PROGRESS'], $headers);
        $assignResponse = $this->postJson('/api/v1/admin/support-requests/'.$support['uuid'].'/assign-admin', ['admin_uuid' => $assignee['user_uuid']], $headers);

        foreach ([$statusResponse, $assignResponse] as $response) {
            $json = json_encode($response->json());
            $this->assertStringNotContainsString('password_hash', $json);
            $this->assertSame($support['uuid'], $response->json('data.support_request.uuid'));
        }
    }
}
