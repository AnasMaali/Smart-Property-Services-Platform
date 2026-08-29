<x-emails.layout :heading="'Technician Updated for Your Booking'">
<p>Hello {{ $fields['customer_name'] }},</p>
<p>The technician assigned to your upcoming booking has changed.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0; font-size:14px; color:#334155;">
<tr><td style="padding:4px 0; color:#64748b; width:160px;">Booking</td><td style="padding:4px 0; font-weight:bold;">{{ $fields['booking_number'] }}</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">Service</td><td style="padding:4px 0;">{{ $fields['service_name'] }}</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">New technician</td><td style="padding:4px 0;">{{ $fields['technician_name'] }}</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">Date</td><td style="padding:4px 0;">{{ $fields['appointment_date'] }}</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">Time</td><td style="padding:4px 0;">{{ $fields['appointment_start_time'] }} - {{ $fields['appointment_end_time'] }} ({{ $fields['time_window'] }})</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">Address</td><td style="padding:4px 0;">{{ $fields['address_summary'] }}</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">Amount paid</td><td style="padding:4px 0;">@if ($fields['paid_amount'] !== null){{ $fields['paid_amount'] }} {{ $fields['currency'] }}@else Covered by your service contract @endif</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">Status</td><td style="padding:4px 0;">{{ $fields['booking_status'] }}</td></tr>
</table>
</x-emails.layout>
