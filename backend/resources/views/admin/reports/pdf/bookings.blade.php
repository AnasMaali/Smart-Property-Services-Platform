@php
    $fmt = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    @include('admin.reports.pdf.partials.styles')
</head>
<body>

@include('admin.reports.pdf.partials.header', [
    'title' => 'Booking Report',
    'rangeFrom' => $export['range']['from'],
    'rangeTo' => $export['range']['to'],
    'generatedAt' => $generatedAt,
])

<table class="summary-grid">
    <tr>
        <td><span class="label">Total Bookings</span><span class="value">{{ $export['summary']['total_bookings'] }}</span></td>
        <td><span class="label">Completed</span><span class="value">{{ $export['summary']['completed'] }}</span></td>
        <td><span class="label">Cancelled</span><span class="value">{{ $export['summary']['cancelled'] }}</span></td>
        <td><span class="label">Active / In Progress</span><span class="value">{{ $export['summary']['active'] }}</span></td>
    </tr>
</table>

@if ($export['truncated'])
    <p class="truncation-note">Showing the first {{ $maxRows }} of {{ $export['total'] }} matching Bookings. Use the CSV export for the complete result set.</p>
@endif

@if (empty($export['rows']))
    <p class="empty-note">No Bookings match these filters.</p>
@else
    <table class="report-table">
        <thead>
            <tr>
                <th>Booking #</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Source</th>
                <th>Services</th>
                <th>Appointment</th>
                <th>Payment</th>
                <th>Total</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($export['rows'] as $row)
                <tr>
                    <td>{{ $row['booking_number'] }}</td>
                    <td>{{ $row['customer_name'] ?? '—' }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td>{{ $row['source'] }}</td>
                    <td>{{ $row['services'] ?: '—' }}</td>
                    <td>{{ $fmt($row['appointment_at']) }}</td>
                    <td>{{ $row['payment_method'] ?? 'CONTRACT' }}</td>
                    <td>{{ $row['total'] }} {{ $row['currency_code'] }}</td>
                    <td>{{ $fmt($row['created_at']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
