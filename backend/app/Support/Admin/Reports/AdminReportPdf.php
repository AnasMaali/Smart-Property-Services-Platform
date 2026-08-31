<?php

namespace App\Support\Admin\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/**
 * The one place every BLUE V1 Admin Report PDF export renders from -
 * server-side HTML-to-PDF via dompdf/dompdf (pure-PHP, no external
 * wkhtmltopdf binary - see this feature's PR description for the license/
 * PHP-8.3/Laravel-13 compatibility check performed before adding it).
 * Renders the exact same Blade view data every report's screen/CSV share -
 * never a second computation of a total.
 *
 * `isRemoteEnabled` stays false: a report PDF must never fetch an external
 * image/stylesheet at render time (no SSRF surface, no dependency on
 * network availability at export time) - every asset a report view needs
 * must be inlined (a data: URI) or omitted entirely.
 */
final class AdminReportPdf
{
    public static function render(string $view, array $data): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data)->render(), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public static function response(string $view, array $data, string $filename): Response
    {
        return response(self::render($view, $data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
