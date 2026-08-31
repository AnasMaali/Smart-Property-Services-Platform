{{-- Shared inline PDF stylesheet for every BLUE V1 Admin Report PDF (App\Support\Admin\Reports\AdminReportPdf).
     dompdf only reliably applies CSS declared in the document it renders, so this partial is @include'd
     (never linked externally - App\Support\Admin\Reports\AdminReportPdf sets isRemoteEnabled=false) into
     each report's own <head>. Kept deliberately plain/print-safe: no color gradients, no web fonts. --}}
<style>
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #0f172a; margin: 0; padding: 24px; }
    .report-header { border-bottom: 2px solid #0f172a; padding-bottom: 10px; margin-bottom: 16px; }
    .report-title { font-size: 18px; font-weight: bold; margin: 0; }
    .report-subtitle { font-size: 10px; color: #475569; margin: 4px 0 0; }
    .report-meta { font-size: 9px; color: #64748b; margin-top: 4px; }
    .summary-grid { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .summary-grid td { border: 1px solid #cbd5e1; padding: 6px 8px; width: 25%; }
    .summary-grid .label { display: block; font-size: 8px; text-transform: uppercase; color: #64748b; }
    .summary-grid .value { display: block; font-size: 12px; font-weight: bold; margin-top: 2px; }
    table.report-table { width: 100%; border-collapse: collapse; }
    table.report-table th { background: #0f172a; color: #ffffff; text-align: left; padding: 5px 6px; font-size: 8px; text-transform: uppercase; }
    table.report-table td { border-bottom: 1px solid #e2e8f0; padding: 5px 6px; font-size: 9px; }
    table.report-table tr:nth-child(even) td { background: #f8fafc; }
    .truncation-note { margin-top: 10px; font-size: 9px; color: #b45309; }
    .empty-note { padding: 12px 0; color: #64748b; }
</style>
