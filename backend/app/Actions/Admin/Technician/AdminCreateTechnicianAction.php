<?php

namespace App\Actions\Admin\Technician;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminTechnicianPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Technician Admin Management - creates one `technicians` row.
 * Always inserted with `status_id` resolved to the INACTIVE
 * `technician_statuses` row regardless of any client-supplied status,
 * mirroring App\Actions\Admin\Service\AdminCreateServiceAction's
 * "created inactive, activate explicitly" convention - a brand-new
 * Technician must never become eligible for assignment before an Admin has
 * configured at least one specialization for them. App\Actions\Admin\
 * Technician\AdminSetTechnicianStatusAction is the only way a Technician
 * is ever moved to a different status.
 */
final class AdminCreateTechnicianAction
{
    use BuildsCartResult;

    /**
     * @param  array{employee_code: string, full_name: string, phone_number: string, email: ?string, is_phone_visible_to_customer: bool, internal_note: ?string}  $data
     */
    public function handle(Request $request, User $actor, array $data): array
    {
        return DB::transaction(function () use ($request, $actor, $data): array {
            $errors = [];

            if (DB::table('technicians')->where('employee_code', $data['employee_code'])->exists()) {
                $errors['employee_code'] = ['This employee code is already in use.'];
            }

            if (DB::table('technicians')->where('phone_number', $data['phone_number'])->exists()) {
                $errors['phone_number'] = ['This phone number is already in use.'];
            }

            if ($data['email'] !== null && DB::table('technicians')->where('email', $data['email'])->exists()) {
                $errors['email'] = ['This email is already in use.'];
            }

            if ($errors !== []) {
                return $this->unprocessable('The given data was invalid.', $errors);
            }

            $inactiveStatusId = (int) DB::table('technician_statuses')->where('code', 'INACTIVE')->value('id');
            $technicianUuid = UuidBinary::generate();
            $now = now();

            DB::table('technicians')->insert([
                'id' => UuidBinary::toBinary($technicianUuid),
                'employee_code' => $data['employee_code'],
                'status_id' => $inactiveStatusId,
                'full_name' => $data['full_name'],
                'phone_number' => $data['phone_number'],
                'email' => $data['email'],
                'is_phone_visible_to_customer' => $data['is_phone_visible_to_customer'] ? 1 : 0,
                'internal_note' => $data['internal_note'],
                'status_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'TECHNICIAN_CREATED',
                'TECHNICIAN',
                $technicianUuid,
                ['employee_code' => $data['employee_code'], 'full_name' => $data['full_name'], 'phone_number' => $data['phone_number']],
            );

            $created = AdminTechnicianPresenter::loadForDetail(UuidBinary::toBinary($technicianUuid));

            return $this->ok(201, 'Technician created successfully (inactive - add specializations, then activate).', ['technician' => AdminTechnicianPresenter::detail($created)]);
        });
    }
}
