<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports\Audit;

use App\Actions\Admin\Reports\AdminExportAuditLogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportAdminAuditLogRequest;
use App\Support\Admin\Reports\AdminReportFilename;
use App\Support\Admin\Reports\AdminReportPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ExportAdminAuditLogPdfController extends Controller
{
    public function __invoke(ExportAdminAuditLogRequest $request, AdminExportAuditLogAction $action): Response|JsonResponse
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

        $export = $action->exportRows($filters, AdminExportAuditLogAction::MAX_PDF_ROWS);

        if ($export === null) {
            return response()->json(['success' => false, 'message' => 'Invalid Audit Log export filters.'], 422);
        }

        return AdminReportPdf::response(
            'admin.reports.pdf.audit-log',
            [
                'export' => $export,
                'generatedAt' => now()->toIso8601String(),
                'maxRows' => AdminExportAuditLogAction::MAX_PDF_ROWS,
                'rangeFrom' => $filters['from'] ?? null,
                'rangeTo' => $filters['to'] ?? null,
            ],
            AdminReportFilename::build('audit-log', 'pdf')
        );
    }
}
