<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Audit;

use App\Actions\Admin\Reports\AdminExportAuditLogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportAdminAuditLogRequest;
use App\Support\Admin\Reports\AdminReportCsv;
use App\Support\Admin\Reports\AdminReportFilename;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportAdminAuditLogCsvController extends Controller
{
    public function __invoke(ExportAdminAuditLogRequest $request, AdminExportAuditLogAction $action): StreamedResponse|JsonResponse
    {
        $filters = array_filter([
            'action_code' => $request->string('action_code')->toString() ?: null,
            'entity_type' => $request->string('entity_type')->toString() ?: null,
            'entity_identifier' => $request->string('entity_identifier')->toString() ?: null,
            'was_successful' => $request->has('was_successful') ? $request->boolean('was_successful') : null,
            'actor_uuid' => $request->string('actor_uuid')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ], fn ($value) => $value !== null);

        $export = $action->exportRows($filters);

        if ($export === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Audit Log export filters.'], 422);
        }

        $rows = (function () use ($export) {
            foreach ($export['rows'] as $row) {
                yield [
                    $row['created_at'], $row['action_code'], $row['entity_type'], $row['entity_identifier'],
                    $row['was_successful'] ? 'Success' : 'Failed', $row['failure_reason'],
                    $row['actor']['full_name'] ?? null,
                ];
            }
        })();

        return AdminReportCsv::stream(
            AdminReportFilename::build('audit-log', 'csv'),
            ['Created At', 'Action Code', 'Entity Type', 'Entity Identifier', 'Outcome', 'Failure Reason', 'Actor'],
            $rows
        );
    }
}
