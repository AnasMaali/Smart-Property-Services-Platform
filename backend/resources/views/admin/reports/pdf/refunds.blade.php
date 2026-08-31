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
    'title' => 'Refund Report',
    'rangeFrom' => $export['range']['from'],
    'rangeTo' => $export['range']['to'],
    'generatedAt' => $generatedAt,
])

<table class="summary-grid">
    <tr>
        <td><span class="label">Confirmed Refunds</span><span class="value">{{ $export['summary']['confirmed_count'] }}</span></td>
        <td><span class="label">Confirmed Refunded Total</span><span class="value">{{ $export['summary']['confirmed_total'] }} {{ $currency['code'] ?? '' }}</span></td>
        <td><span class="label">Pending</span><span class="value">{{ $export['summary']['pending_count'] }}</span></td>
        <td><span class="label">Failed</span><span class="value">{{ $export['summary']['failed_count'] }}</span></td>
    </tr>
</table>

@if ($export['truncated'])
    <p class="truncation-note">Showing the first {{ $maxRows }} of {{ $export['total'] }} matching refunds. Use the CSV export for the complete result set.</p>
@endif

@if (empty($export['rows']))
    <p class="empty-note">No refunds match these filters.</p>
@else
    <table class="report-table">
        <thead>
            <tr>
                <th>Requested</th>
                <th>Booking #</th>
                <th>Original Payment Ref.</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Reason</th>
                <th>Succeeded</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($export['rows'] as $row)
                <tr>
                    <td>{{ $fmt($row['requested_at']) }}</td>
                    <td>{{ $row['booking_number'] ?? '—' }}</td>
                    <td>{{ $row['original_payment_reference'] ?? '—' }}</td>
                    <td>{{ $row['amount'] }} {{ $row['currency_code'] }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td>{{ $row['reason'] ?? '—' }}</td>
                    <td>{{ $fmt($row['succeeded_at']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
