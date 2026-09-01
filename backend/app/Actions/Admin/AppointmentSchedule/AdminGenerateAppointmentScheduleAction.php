<?php

namespace App\Actions\Admin\AppointmentSchedule;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\AppointmentScheduleDate;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). Generates one
 * `appointment_slots` row per active `appointment_time_windows` template,
 * per date, across an inclusive [from, to] Dubai-calendar-date range - the
 * production mechanism the BLUE V1 Admin Closure QA audit proved did not
 * exist at all before this phase.
 *
 * IDEMPOTENT by construction: each candidate row is written with
 * `insertOrIgnore()`, which relies on the existing
 * `uq_appointment_slots_period` UNIQUE(starts_at, ends_at) constraint - a
 * slot that already exists (from an earlier generator run, or a manually
 * created/edited slot) is silently left untouched, never updated,
 * overwritten, or duplicated, regardless of how many times this Action
 * runs over overlapping ranges. This is genuine DB-level idempotency, not
 * merely an application-level "have I seen this before" check - safe even
 * under concurrent generator invocations.
 *
 * A capped range (max 366 days) guards against an accidentally enormous
 * request; there is no product requirement for anything larger.
 */
final class AdminGenerateAppointmentScheduleAction
{
    use BuildsCartResult;

    private const MAX_DAYS = 366;

    private const DEFAULT_CAPACITY = 3;

    public function handle(Request $request, User $actor, string $from, string $to, ?int $bookingCapacity): array
    {
        $fromRange = AppointmentScheduleDate::utcDayRange($from);
        $toRange = AppointmentScheduleDate::utcDayRange($to);

        if ($fromRange === null || $toRange === null) {
            return $this->unprocessable('The given data was invalid.', ['from' => ['The from/to dates must be real calendar dates in Y-m-d format.']]);
        }

        $timezone = AppointmentScheduleDate::timezone();
        $fromDay = Carbon::createFromFormat('Y-m-d', $from, $timezone)->startOfDay();
        $toDay = Carbon::createFromFormat('Y-m-d', $to, $timezone)->startOfDay();

        if ($fromDay->greaterThan($toDay)) {
            return $this->unprocessable('The given data was invalid.', ['to' => ['The to date must not be before the from date.']]);
        }

        $dayCount = (int) $fromDay->diffInDays($toDay) + 1;

        if ($dayCount > self::MAX_DAYS) {
            return $this->unprocessable('The given data was invalid.', ['to' => ['The date range cannot exceed '.self::MAX_DAYS.' days.']]);
        }

        $capacity = $bookingCapacity ?? self::DEFAULT_CAPACITY;

        $activeWindows = DB::table('appointment_time_windows')->where('is_active', 1)->orderBy('display_order')->get();
        $inactiveWindowCount = (int) DB::table('appointment_time_windows')->where('is_active', 0)->count();

        if ($activeWindows->isEmpty()) {
            return $this->unprocessable('The given data was invalid.', ['from' => ['There are no active appointment time window templates to generate from.']]);
        }

        $now = now();
        $created = 0;
        $alreadyExisted = 0;

        DB::transaction(function () use ($activeWindows, $dayCount, $fromDay, $timezone, $capacity, $now, &$created, &$alreadyExisted): void {
            for ($offset = 0; $offset < $dayCount; $offset++) {
                $day = $fromDay->copy()->addDays($offset);

                foreach ($activeWindows as $window) {
                    $startsAt = Carbon::createFromFormat(
                        'Y-m-d H:i:s',
                        $day->format('Y-m-d').' '.$window->start_time,
                        $timezone
                    )->clone()->setTimezone('UTC');

                    $endsAt = Carbon::createFromFormat(
                        'Y-m-d H:i:s',
                        $day->format('Y-m-d').' '.$window->end_time,
                        $timezone
                    )->clone()->setTimezone('UTC');

                    $inserted = DB::table('appointment_slots')->insertOrIgnore([[
                        'id' => UuidBinary::toBinary(UuidBinary::generate()),
                        'starts_at' => $startsAt->format('Y-m-d H:i:s.u'),
                        'ends_at' => $endsAt->format('Y-m-d H:i:s.u'),
                        'booking_capacity' => $capacity,
                        'time_window_id' => $window->id,
                        'is_active' => 1,
                        'internal_note' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]]);

                    if ($inserted > 0) {
                        $created++;
                    } else {
                        $alreadyExisted++;
                    }
                }
            }
        });

        AdminAuditLogger::record(
            $request,
            $actor,
            'APPOINTMENT_SLOTS_GENERATED',
            'APPOINTMENT_SCHEDULE',
            null,
            ['from' => $from, 'to' => $to, 'booking_capacity' => $capacity, 'created' => $created, 'already_existed' => $alreadyExisted, 'active_windows' => $activeWindows->count()],
        );

        return $this->ok(200, 'Appointment schedule generated successfully.', [
            'from' => $from,
            'to' => $to,
            'booking_capacity' => $capacity,
            'active_time_windows' => $activeWindows->count(),
            'inactive_time_windows_skipped' => $inactiveWindowCount,
            'days' => $dayCount,
            'created' => $created,
            'already_existed' => $alreadyExisted,
            'failed' => 0,
        ]);
    }
}
