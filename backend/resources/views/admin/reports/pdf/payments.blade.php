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
    'title' => 'Payment Report',
    'rangeFrom' => $export['range']['from'],
    'rangeTo' => $export['range']['to'],
    'generatedAt' => $generatedAt,
])

<table class="summary-grid">
    <tr>
        <td><span class="label">Total Payments</span><span class="value">{{ $export['summary']['total_payments'] }}</span></td>
        <td><span class="label">Successful</span><span class="value">{{ $export['summary']['successful_count'] }}</span></td>
        <td><span class="label">Failed</span><span class="value">{{ $export['summary']['failed_count'] }}</span></td>
        <td><span class="label">Pending</span><span class="value">{{ $export['summary']['pending_count'] }}</span></td>
    </tr>
    <tr>
        <td colspan="4"><span class="label">Successful Amount Total</span><span class="value">{{ $export['summary']['successful_amount_total'] }}</span></td>
    </tr>
</table>

@if ($export['truncated'])
    <p class="truncation-note">Showing the first {{ $maxRows }} of {{ $export['total'] }} matching payments. Use the CSV export for the complete result set.</p>
@endif

@if (empty($export['rows']))
    <p class="empty-note">No payments match these filters.</p>
@else
    <table class="report-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Booking #</th>
                <th>Customer</th>
                <th>Method</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Reference</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($export['rows'] as $row)
                <tr>
                    <td>{{ $fmt($row['created_at']) }}</td>
                    <td>{{ $row['booking_number'] ?? '—' }}</td>
                    <td>{{ $row['customer_name'] ?? '—' }}</td>
                    <td>{{ $row['payment_method'] }}</td>
                    <td>{{ $row['amount'] }} {{ $row['currency_code'] }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td>{{ $row['provider_reference'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
