<?php

namespace App\Support\Admin\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one place every BLUE V1 Admin Report CSV export streams its output
 * from - screen/CSV/PDF for a given report all read the same authoritative
 * rows (see each App\Actions\Admin\Reports\* Action), this class only ever
 * turns those rows into a safe CSV byte stream. Never materializes the full
 * result set into memory: $rows is any `iterable` (a lazy `LazyCollection`/
 * generator from a DB cursor for an unbounded export, or a plain array for a
 * small bounded one) and is written to `php://output` row-by-row inside the
 * StreamedResponse callback, so an export can represent the COMPLETE
 * filtered result set without ever loading it all into PHP memory at once.
 */
final class AdminReportCsv
{
    /**
     * Leading characters Excel/Sheets/LibreOffice may interpret as the
     * start of a formula if a cell value is opened unescaped - CSV formula
     * injection (OWASP). Any user-controlled string starting with one of
     * these gets a leading `'` prepended, which every common spreadsheet
     * application renders as literal text, never evaluates.
     */
    private const FORMULA_TRIGGER_CHARS = ['=', '+', '-', '@'];

    /**
     * @param  array<int, string>  $header
     * @param  iterable<int, array<int, mixed>>  $rows  Each row must already be in $header's exact column order.
     */
    public static function stream(string $filename, array $header, iterable $rows): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($header, $rows): void {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM - makes Excel on Windows (which otherwise assumes
            // the system codepage) render non-ASCII customer/service names
            // correctly rather than as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $header);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::sanitizeCell(...), $row));
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private static function sanitizeCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        if ($value === '') {
            return $value;
        }

        return in_array($value[0], self::FORMULA_TRIGGER_CHARS, true) ? "'".$value : $value;
    }
}
