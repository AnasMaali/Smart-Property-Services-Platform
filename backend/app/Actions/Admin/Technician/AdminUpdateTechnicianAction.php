<?php

namespace App\Actions\Admin\Technician;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminTechnicianPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Technician Admin Management - edits operational Technician
 * profile fields only: full_name, phone_number, email,
 * is_phone_visible_to_customer, internal_note. `employee_code` is
 * immutable from creation onward (mirrors `services.code`/`slug` - see
 * App\Actions\Admin\Service\AdminUpdateServiceMetadataAction's docblock)
 * and status/specializations are mutated only through
 * AdminSetTechnicianStatusAction / AdminSetTechnicianSpecializationAction.
 *
 * Never rewrites historical data: `technician_assignments` and
 * `booking_items` never snapshot Technician name/phone/email, so an edit
 * here changes nothing about an already-committed assignment or Booking
 * record - see AdminTechnicianPresenter's job-history query, which always
 * reads the live `technicians` row for display, exactly like every other
 * live-technician-identity read in this codebase (e.g. AdminBookingPresenter).
 */
final class AdminUpdateTechnicianAction
{
    use BuildsCartResult;

    /**
     * @param  array{full_name?: string, phone_number?: string, email?: ?string, is_phone_visible_to_customer?: bool, internal_note?: ?string}  $data
     */
    public function handle(Request $request, string $technicianUuid, User $actor, array $data): array
    {
        try {
            $technicianIdBinary = UuidBinary::toBinary($technicianUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Technician not found.');
        }

        return DB::transaction(function () use ($request, $technicianUuid, $technicianIdBinary, $actor, $data): array {
            $technician = DB::table('technicians')->where('id', $technicianIdBinary)->lockForUpdate()->first();

            if ($technician === null) {
                return $this->notFound('Technician not found.');
            }

            $errors = [];

            if (isset($data['phone_number']) && $data['phone_number'] !== $technician->phone_number
                && DB::table('technicians')->where('phone_number', $data['phone_number'])->where('id', '!=', $technicianIdBinary)->exists()) {
                $errors['phone_number'] = ['This phone number is already in use.'];
            }

            if (array_key_exists('email', $data) && $data['email'] !== null && $data['email'] !== $technician->email
                && DB::table('technicians')->where('email', $data['email'])->where('id', '!=', $technicianIdBinary)->exists()) {
                $errors['email'] = ['This email is already in use.'];
            }

            if ($errors !== []) {
                return $this->unprocessable('The given data was invalid.', $errors);
            }

            $updates = [];
            $oldValues = [];
            $newValues = [];

            foreach (['full_name', 'phone_number', 'internal_note', 'is_phone_visible_to_customer'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $value = $field === 'is_phone_visible_to_customer' ? ($data[$field] ? 1 : 0) : $data[$field];

                if ($value !== $technician->{$field}) {
                    $oldValues[$field] = $technician->{$field};
                    $newValues[$field] = $value;
                    $updates[$field] = $value;
                }
            }

            if (array_key_exists('email', $data) && $data['email'] !== $technician->email) {
                $oldValues['email'] = $technician->email;
                $newValues['email'] = $data['email'];
                $updates['email'] = $data['email'];
            }

            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('technicians')->where('id', $technicianIdBinary)->update($updates);

                AdminAuditLogger::record($request, $actor, 'TECHNICIAN_UPDATED', 'TECHNICIAN', $technicianUuid, $newValues, $oldValues);
            }

            $updated = AdminTechnicianPresenter::loadForDetail($technicianIdBinary);

            return $this->ok(200, 'Technician updated successfully.', ['technician' => AdminTechnicianPresenter::detail($updated)]);
        });
    }
}
