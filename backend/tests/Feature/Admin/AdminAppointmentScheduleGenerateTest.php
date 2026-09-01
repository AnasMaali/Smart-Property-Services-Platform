<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management) -
 * App\Actions\Admin\AppointmentSchedule\AdminGenerateAppointmentScheduleAction:
 * idempotency, active-window scoping, and Dubai-calendar-day -> UTC
 * conversion.
 *
 * Every test here deactivates the six real, seeded `appointment_time_windows`
 * rows (database/blue_v1_seed.sql, "33. APPOINTMENT TIME WINDOWS") at the
 * start of its own transaction, then creates its own fixture window(s) -
 * this is test isolation only, safely undone by DatabaseTransactions'
 * automatic rollback after each test; it never mutates the real seed data.
 * Without this, AdminGenerateAppointmentScheduleAction correctly (this is
 * the intended production behavior) picks up all six real active windows
 * alongside a test's own, making exact "N active windows" assertions
 * flaky/wrong once real reference data exists.
 */
class AdminAppointmentScheduleGenerateTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
        DB::table('appointment_time_windows')->update(['is_active' => 0]);
    }

    private function generate(?string $accessToken, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/admin/appointment-schedule/generate', $payload, $accessToken === null ? [] : $this->bearer($accessToken));
    }

    private function createWindow(array $overrides = []): int
    {
        $now = now();

        return DB::table('appointment_time_windows')->insertGetId(array_merge([
            'code' => 'GEN_TEST_'.uniqid(),
            'name' => 'Generator Test Window',
            'description' => null,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'display_order' => 1,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    public function test_generating_one_day_creates_one_slot_per_active_window_with_the_requested_capacity(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->createWindow(['start_time' => '09:00:00', 'end_time' => '11:00:00', 'display_order' => 1]);
        $this->createWindow(['start_time' => '11:00:00', 'end_time' => '13:00:00', 'display_order' => 2]);
        $this->createWindow(['is_active' => 0, 'start_time' => '13:00:00', 'end_time' => '15:00:00', 'display_order' => 3]);

        $date = now()->addDays(30)->format('Y-m-d');

        $response = $this->generate($admin['access_token'], ['from' => $date, 'to' => $date, 'booking_capacity' => 3])
            ->assertStatus(200);

        $this->assertSame(2, $response->json('data.created'));
        $this->assertSame(0, $response->json('data.already_existed'));
        $this->assertSame(2, $response->json('data.active_time_windows'));
        $this->assertSame(
            DB::table('appointment_time_windows')->where('is_active', 0)->count(),
            $response->json('data.inactive_time_windows_skipped'),
            'inactive_time_windows_skipped must reflect every currently-inactive template, not just the ones this test created.'
        );

        $slots = DB::table('appointment_slots')->whereDate('starts_at', '>=', now())->where('booking_capacity', 3)->get();
        $this->assertGreaterThanOrEqual(2, $slots->count());
    }

    public function test_running_the_generator_twice_never_duplicates_slots(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->createWindow();
        $date = now()->addDays(31)->format('Y-m-d');

        $first = $this->generate($admin['access_token'], ['from' => $date, 'to' => $date, 'booking_capacity' => 3])->assertStatus(200);
        $this->assertSame(1, $first->json('data.created'));

        $second = $this->generate($admin['access_token'], ['from' => $date, 'to' => $date, 'booking_capacity' => 3])->assertStatus(200);
        $this->assertSame(0, $second->json('data.created'));
        $this->assertSame(1, $second->json('data.already_existed'));
    }

    public function test_date_range_generates_one_slot_per_window_per_day(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->createWindow();
        $this->createWindow(['start_time' => '11:00:00', 'end_time' => '13:00:00']);

        $from = now()->addDays(40)->format('Y-m-d');
        $to = now()->addDays(44)->format('Y-m-d');

        $response = $this->generate($admin['access_token'], ['from' => $from, 'to' => $to])->assertStatus(200);

        $this->assertSame(5, $response->json('data.days'));
        $this->assertSame(10, $response->json('data.created'));
        $this->assertSame(3, $response->json('data.booking_capacity'), 'Default capacity must be 3 when not explicitly overridden.');
    }

    public function test_existing_manually_edited_slot_is_never_overwritten(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $windowId = $this->createWindow(['start_time' => '09:00:00', 'end_time' => '11:00:00']);
        $date = now()->addDays(50)->format('Y-m-d');

        // The manual slot must occupy the EXACT starts_at/ends_at the
        // generator would itself compute for this window+date (09:00-11:00
        // Dubai = 05:00-07:00 UTC) - otherwise it is a different
        // uq_appointment_slots_period key and this test would not actually
        // prove anything about "not overwritten" (it would just create a
        // second, non-colliding slot).
        $manualSlot = $this->createAppointmentSlot([
            'starts_at' => Carbon::parse($date.' 05:00:00', 'UTC'),
            'ends_at' => Carbon::parse($date.' 07:00:00', 'UTC'),
            'time_window_id' => $windowId,
            'booking_capacity' => 7,
            'internal_note' => 'Manually adjusted by an operator - never touch.',
        ]);

        $response = $this->generate($admin['access_token'], ['from' => $date, 'to' => $date, 'booking_capacity' => 3])
            ->assertStatus(200);

        $this->assertSame(0, $response->json('data.created'));
        $this->assertSame(1, $response->json('data.already_existed'));

        $slotAfter = DB::table('appointment_slots')->where('id', UuidBinary::toBinary($manualSlot['uuid']))->first();
        $this->assertSame(7, (int) $slotAfter->booking_capacity, 'The generator must never overwrite a manually-edited existing slot.');
        $this->assertSame('Manually adjusted by an operator - never touch.', $slotAfter->internal_note);
    }

    public function test_dubai_calendar_day_converts_correctly_to_stored_utc(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        // 05:00 Dubai (UTC+4) is 01:00 UTC the SAME calendar date.
        $this->createWindow(['start_time' => '05:00:00', 'end_time' => '07:00:00']);
        $date = now()->addDays(60)->format('Y-m-d');

        $this->generate($admin['access_token'], ['from' => $date, 'to' => $date])->assertStatus(200);

        $slot = DB::table('appointment_slots')->orderByDesc('created_at')->first();
        $this->assertSame($date.' 01:00:00.000000', $slot->starts_at, 'A 05:00 Dubai start must be stored as 01:00 UTC on the same calendar date.');
    }

    public function test_no_active_windows_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->createWindow(['is_active' => 0]);
        $date = now()->addDays(70)->format('Y-m-d');

        $this->generate($admin['access_token'], ['from' => $date, 'to' => $date])->assertStatus(422);
    }

    /**
     * Unlike every other test in this class, this one deliberately does NOT
     * rely on setUp()'s blanket deactivation - it proves the literal,
     * real-world scenario against the actual six seeded
     * `appointment_time_windows` rows (database/blue_v1_seed.sql, "33.
     * APPOINTMENT TIME WINDOWS"), reactivating them first in case an
     * earlier test in this run left one inactive (DatabaseTransactions
     * rolls this back afterward - the real seed data is never permanently
     * touched).
     */
    public function test_the_real_six_seeded_windows_generate_six_dated_slots_for_one_day(): void
    {
        DB::table('appointment_time_windows')
            ->whereIn('code', ['W_0900_1100', 'W_1100_1300', 'W_1300_1500', 'W_1500_1700', 'W_1700_1900', 'W_1900_2100'])
            ->update(['is_active' => 1]);

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $date = now()->addDays(90)->format('Y-m-d');

        $response = $this->generate($admin['access_token'], ['from' => $date, 'to' => $date])->assertStatus(200);

        $this->assertSame(6, $response->json('data.active_time_windows'));
        $this->assertSame(6, $response->json('data.created'));
        $this->assertSame(3, $response->json('data.booking_capacity'));

        $windows = DB::table('appointment_time_windows')
            ->whereIn('code', ['W_0900_1100', 'W_1100_1300', 'W_1300_1500', 'W_1500_1700', 'W_1700_1900', 'W_1900_2100'])
            ->orderBy('display_order')
            ->get();

        foreach ($windows as $window) {
            $expectedStartsAt = Carbon::parse($date.' '.$window->start_time, 'Asia/Dubai')->setTimezone('UTC')->format('Y-m-d H:i:s.u');
            $slot = DB::table('appointment_slots')
                ->where('time_window_id', $window->id)
                ->where('starts_at', $expectedStartsAt)
                ->first();

            $this->assertNotNull($slot, "Expected a generated slot for {$window->code} at {$expectedStartsAt} (UTC).");
            $this->assertSame(3, (int) $slot->booking_capacity);
            $this->assertSame(1, (int) $slot->is_active);
        }
    }

    public function test_view_only_admin_cannot_generate(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'appointments.manage')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $date = now()->addDays(80)->format('Y-m-d');

        $this->generate($admin['access_token'], ['from' => $date, 'to' => $date])->assertStatus(403);
    }
}
