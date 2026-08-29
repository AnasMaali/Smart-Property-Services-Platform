{{-- BLUE V1 Phase B22 - shared transactional-email shell for every
     Technician/Customer notification email (App\Mail\*). Table-based,
     inline-styled markup only - no external stylesheet, no JavaScript - so
     it renders consistently across Outlook/Gmail/mobile mail clients. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:Arial, Helvetica, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9; padding:24px 0;">
<tr>
<td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border-radius:12px; overflow:hidden;">
<tr>
<td style="background-color:#1d4ed8; padding:24px 32px;">
<span style="font-size:20px; font-weight:bold; color:#ffffff; letter-spacing:0.5px;">BLUE</span>
</td>
</tr>
<tr>
<td style="padding:32px;">
<h1 style="margin:0 0 16px 0; font-size:20px; color:#0f172a;">{{ $heading }}</h1>
<div style="font-size:15px; line-height:1.6; color:#334155;">
{{ $slot }}
</div>
</td>
</tr>
<tr>
<td style="padding:20px 32px; background-color:#f8fafc; border-top:1px solid #e2e8f0;">
<p style="margin:0; font-size:12px; color:#94a3b8;">This is an automated message from BLUE. Please do not reply directly to this email.</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
