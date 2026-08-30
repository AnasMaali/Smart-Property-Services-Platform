<?php

namespace App\Actions\Admin\Audit;

use App\Support\Admin\AdminAuditLogPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AdminGetAuditLogAction
{
    use BuildsCartResult;

    public function handle(string $auditLogUuid): array
    {
        try {
            $idBinary = UuidBinary::toBinary($auditLogUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Audit log entry not found.');
        }

        $row = DB::table('admin_audit_logs')->where('id', $idBinary)->first();

        if ($row === null) {
            return $this->notFound('Audit log entry not found.');
        }

        return $this->ok(200, 'Audit log entry retrieved successfully.', ['audit_log' => AdminAuditLogPresenter::detail($row)]);
    }
}
