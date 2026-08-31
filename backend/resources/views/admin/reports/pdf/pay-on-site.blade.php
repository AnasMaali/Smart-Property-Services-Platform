@php
    $fmt = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $currency = $export['summary']['currency'] ?? null;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    @include('admin.reports.pdf.partials.styles')
</head>
<body>

@include('admin.reports.pdf.partials.header', [
    'title' => 'Pay-on-Site Report',
    'rangeFrom' => $export['range']['from'],
    'rangeTo' => $export['range']['to'],
    'generatedAt' => $generatedAt,
])

<table class="summary-grid">
    <tr>
        <td><span class="label">Collected Amount</span><span class="value">{{ $export['summary']['collected_amount'] }} {{ $currency['code'] ?? '' }}</span></td>
        <td><span class="label">Collected Count</span><span class="value">{{ $export['summary']['collected_count'] }}</span></td>
        <td><span class="label">Pending Amount (current)</span><span class="value">{{ $export['summary']['pending_amount'] }} {{ $currency['code'] ?? '' }}</span></td>
        <td><span class="label">Pending Count (current)</span><span class="value">{{ $export['summary']['pending_count'] }}</span></td>
    </tr>
</table>

@if ($export['truncated'])
    <p class="truncation-note">Showing the first {{ $maxRows }} of {{ $export['total'] }} matching settlements. Use the CSV export for the complete result set.</p>
@endif

@if (empty($export['rows']))
    <p class="empty-note">No Pay-on-Site settlements match these filters.</p>
@else
    <table class="report-table">
        <thead>
            <tr>
                <th>Booking #</th>
                <th>Customer</th>
                <th>Amount Due</th>
                <th>Amount Collected</th>
                <th>Status</th>
                <th>Collected At</th>
                <th>Collected By</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($export['rows'] as $row)
                <tr>
                    <td>{{ $row['booking_number'] }}</td>
                    <td>{{ $row['customer_name'] ?? '—' }}</td>
                    <td>{{ $row['amount_due'] }}</td>
                    <td>{{ $row['amount_collected'] ?? '—' }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td>{{ $fmt($row['collected_at']) }}</td>
                    <td>{{ $row['collected_by'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
