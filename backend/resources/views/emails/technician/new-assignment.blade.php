<x-emails.layout :heading="'New Service Assignment'">
<p>Hello {{ $fields['technician_name'] }},</p>
<p>A new service has been assigned to you.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0; font-size:14px; color:#334155;">
<tr><td style="padding:4px 0; color:#64748b; width:160px;">Booking</td><td style="padding:4px 0; font-weight:bold;">{{ $fields['booking_number'] }}</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">Service</td><td style="padding:4px 0;">{{ $fields['service_details'] }}</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">Date</td><td style="padding:4px 0;">{{ $fields['appointment_date'] }}</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">Time</td><td style="padding:4px 0;">{{ $fields['appointment_start_time'] }} - {{ $fields['appointment_end_time'] }} ({{ $fields['time_window'] }})</td></tr>
<tr><td style="padding:4px 0; color:#64748b;">Customer</td><td style="padding:4px 0;">{{ $fields['customer_name'] }}</td></tr>
@if ($fields['visit_contact_phone'] !== '')
<tr><td style="padding:4px 0; color:#64748b;">Contact</td><td style="padding:4px 0;">{{ $fields['visit_contact_phone'] }}</td></tr>
@endif
</table>

<p style="margin:0 0 4px 0; font-weight:bold; color:#0f172a;">Location</p>
<p style="margin:0;">{{ $fields['property_type'] }}@if($fields['building'] !== ''), {{ $fields['building'] }}@endif</p>
@if ($fields['floor'] !== '' || $fields['unit'] !== '')
<p style="margin:0;">
@if ($fields['floor'] !== '') Floor {{ $fields['floor'] }}@endif
@if ($fields['floor'] !== '' && $fields['unit'] !== '') - @endif
@if ($fields['unit'] !== '') Unit {{ $fields['unit'] }}@endif
</p>
@endif
<p style="margin:0;">{{ $fields['street'] }}</p>
<p style="margin:0;">{{ $fields['area'] }}, {{ $fields['city'] }}</p>
@if ($fields['landmark'] !== '')
<p style="margin:8px 0 0 0;"><strong>Nearby landmark:</strong> {{ $fields['landmark'] }}</p>
@endif
@if ($fields['location_notes'] !== '')
<p style="margin:8px 0 0 0;"><strong>Location notes:</strong> {{ $fields['location_notes'] }}</p>
@endif

<p style="margin:24px 0 0 0;">Please arrive during the scheduled appointment window.</p>
</x-emails.layout>
