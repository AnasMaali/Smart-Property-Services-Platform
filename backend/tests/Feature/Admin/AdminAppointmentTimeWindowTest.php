<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management) -
 * App\Actions\Admin\AppointmentSchedule\Admin*AppointmentTimeWindowAction
 * CRUD + authorization for the `appointments.view`/`appointments.manage`
 * capabilities.
 */
class AdminAppointmentTimeWindowTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function list(?string $accessToken): TestResponse
    {
        return $this->getJson('/api/v1/admin/appointment-time-windows', $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function create(?string $accessToken, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/admin/appointment-time-windows', $payload, $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function update(?string $accessToken, int $id, array $payload): TestResponse
    {
        return $this->patchJson('/api/v1/admin/appointment-time-windows/'.$id, $payload, $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function activate(?string $accessToken, int $id): TestResponse
    {
        return $this->postJson('/api/v1/admin/appointment-time-windows/'.$id.'/activate', [], $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function deactivate(?string $accessToken, int $id): TestResponse
    {
        return $this->postJson('/api/v1/admin/appointment-time-windows/'.$id.'/deactivate', [], $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function denyCapability(string $code): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', $code)->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'W_TEST_'.uniqid(),
            'name' => '09:00 - 11:00',
            'description' => 'Test window.',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'display_order' => 1,
            'is_active' => true,
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

    public function test_unauthenticated_requests_are_denied(): void
    {
        $this->list(null)->assertStatus(401);
        $this->create(null, $this->validPayload())->assertStatus(401);
    }

    public function test_view_only_admin_cannot_mutate(): void
    {
        $this->denyCapability('appointments.manage');
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->list($admin['access_token'])->assertStatus(200);
        $this->create($admin['access_token'], $this->validPayload())->assertStatus(403);
    }

    public function test_admin_without_view_capability_is_denied(): void
    {
        $this->denyCapability('appointments.view');
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->list($admin['access_token'])->assertStatus(403);
    }

    public function test_super_admin_is_allowed_via_the_centralized_override(): void
    {
        $this->denyCapability('appointments.manage');
        $this->denyCapability('appointments.view');
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $this->list($admin['access_token'])->assertStatus(200);
        $this->create($admin['access_token'], $this->validPayload())->assertStatus(201);
    }

    // -----------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------

    public function test_create_and_list_round_trip(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->create($admin['access_token'], $this->validPayload(['code' => 'W_0900_1100_TEST']))
            ->assertStatus(201);

        $this->assertSame('09:00', $response->json('data.appointment_time_window.start_time'));
        $this->assertSame('11:00', $response->json('data.appointment_time_window.end_time'));

        $list = $this->list($admin['access_token'])->assertStatus(200);
        $codes = array_column($list->json('data.appointment_time_windows'), 'code');
        $this->assertContains('W_0900_1100_TEST', $codes);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->create($admin['access_token'], $this->validPayload(['code' => 'W_DUP_TEST']))->assertStatus(201);
        $this->create($admin['access_token'], $this->validPayload(['code' => 'W_DUP_TEST']))->assertStatus(422);
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->create($admin['access_token'], $this->validPayload(['start_time' => '11:00', 'end_time' => '11:00']))->assertStatus(422);
        $this->create($admin['access_token'], $this->validPayload(['start_time' => '11:00', 'end_time' => '09:00']))->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Update / activate / deactivate
    // -----------------------------------------------------------------

    public function test_update_edits_clock_time_without_touching_code_or_active_status(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $created = $this->create($admin['access_token'], $this->validPayload(['code' => 'W_UPDATE_TEST']))->json('data.appointment_time_window');

        $response = $this->update($admin['access_token'], $created['id'], [
            'name' => '10:00 - 12:00 (moved)',
            'description' => null,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'display_order' => 5,
        ])->assertStatus(200);

        $this->assertSame('W_UPDATE_TEST', $response->json('data.appointment_time_window.code'));
        $this->assertSame('10:00', $response->json('data.appointment_time_window.start_time'));
        $this->assertTrue($response->json('data.appointment_time_window.is_active'));
    }

    public function test_activate_and_deactivate_are_idempotent(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $created = $this->create($admin['access_token'], $this->validPayload(['code' => 'W_TOGGLE_TEST']))->json('data.appointment_time_window');

        $this->deactivate($admin['access_token'], $created['id'])->assertStatus(200)->assertJsonPath('data.appointment_time_window.is_active', false);
        $this->deactivate($admin['access_token'], $created['id'])->assertStatus(200)->assertJsonPath('message', 'Appointment time window is already inactive.');

        $this->activate($admin['access_token'], $created['id'])->assertStatus(200)->assertJsonPath('data.appointment_time_window.is_active', true);
        $this->activate($admin['access_token'], $created['id'])->assertStatus(200)->assertJsonPath('message', 'Appointment time window is already active.');
    }

    public function test_deactivating_a_template_never_touches_already_generated_slots(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $created = $this->create($admin['access_token'], $this->validPayload(['code' => 'W_HIST_TEST']))->json('data.appointment_time_window');

        $slot = $this->createAppointmentSlot(['time_window_id' => $created['id'], 'is_active' => 1]);

        $this->deactivate($admin['access_token'], $created['id'])->assertStatus(200);

        $slotAfter = DB::table('appointment_slots')->where('id', UuidBinary::toBinary($slot['uuid']))->first();
        $this->assertSame(1, (int) $slotAfter->is_active, 'Deactivating a template must never deactivate its already-generated slots.');
    }

    public function test_unknown_window_id_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->update($admin['access_token'], 999999999, ['name' => 'Unknown Window', 'description' => null, 'start_time' => '09:00', 'end_time' => '10:00', 'display_order' => 0])->assertStatus(404);
        $this->activate($admin['access_token'], 999999999)->assertStatus(404);
    }
}
