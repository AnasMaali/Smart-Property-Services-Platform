/**
 * Shared authenticated-file-download helper for every BLUE V1 Admin Report
 * CSV/PDF export button (feature "10. CSV EXPORT" / "11. PDF REPORTS").
 * App\Support\Admin\Reports\AdminReportCsv / AdminReportPdf both set a real
 * `Content-Disposition: attachment` header - browsers only honor that on a
 * same-origin navigation with credentials, not a plain `<a href>` click,
 * because the export route requires the Bearer token the centralized
 * Admin API client (../lib/api-client.js) already manages. This fetches the
 * file with that token, then hands the browser the resulting blob via a
 * short-lived object URL - the one client-side download mechanism that
 * still respects the server's own filename.
 */

import { getAccessToken } from './session.js';

export async function downloadReportFile(path, fallbackFilename) {
    const token = getAccessToken();

    const response = await fetch(path, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
    });

    if (!response.ok) {
        let message = 'Unable to generate the export. Please try again.';

        try {
            const payload = await response.json();
            message = payload.message || message;
        } catch {
            // Response body was not JSON (e.g. a raw PDF/CSV error page) - keep the default message.
        }

        throw new Error(message);
    }

    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="?([^"]+)"?/);
    const filename = match ? match[1] : fallbackFilename;

    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
}
