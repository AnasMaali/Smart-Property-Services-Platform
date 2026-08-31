@php
    $currency = $report['summary']['currency'];
    $money = fn ($amount) => number_format((float) $amount, $currency['decimal_places'] ?? 2).' '.($currency['code'] ?? '');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    @include('admin.reports.pdf.partials.styles')
</head>
<body>

@include('admin.reports.pdf.partials.header', [
    'title' => 'Financial Summary Report',
    'rangeFrom' => $report['range']['from'],
    'rangeTo' => $report['range']['to'],
    'generatedAt' => $report['generated_at'],
])

<table class="summary-grid">
    <tr>
        <td><span class="label">Gross Revenue</span><span class="value">{{ $money($report['summary']['gross_revenue']) }}</span></td>
        <td><span class="label">Refunds</span><span class="value">{{ $money($report['summary']['refunds']) }}</span></td>
        <td><span class="label">Net Revenue</span><span class="value">{{ $money($report['summary']['net_revenue']) }}</span></td>
        <td><span class="label">Repair Quote Balance (incl. above)</span><span class="value">{{ $money($report['summary']['repair_quote_balance_collected']) }}</span></td>
    </tr>
    <tr>
        <td><span class="label">Credit Card</span><span class="value">{{ $money($report['summary']['breakdown']['credit_card']) }}</span></td>
        <td><span class="label">Apple Pay</span><span class="value">{{ $money($report['summary']['breakdown']['apple_pay']) }}</span></td>
        <td><span class="label">Pay on Site Collected</span><span class="value">{{ $money($report['summary']['breakdown']['pay_on_site']['collected']) }}</span></td>
        <td><span class="label">Pay on Site Pending (current)</span><span class="value">{{ $money($report['summary']['breakdown']['pay_on_site']['pending']) }}</span></td>
    </tr>
</table>

@if ($report['breakdown_truncated'])
    <p class="truncation-note">Daily breakdown omitted — the selected range exceeds the maximum window for a per-day breakdown. Totals above remain complete and accurate for the full selected period.</p>
@elseif (empty($report['breakdown_by_day']))
    <p class="empty-note">No daily activity in the selected period.</p>
@else
    <table class="report-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Gross Revenue</th>
                <th>Refunds</th>
                <th>Net Revenue</th>
                <th>Credit Card</th>
                <th>Apple Pay</th>
                <th>Pay on Site</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report['breakdown_by_day'] as $day)
                <tr>
                    <td>{{ $day['date'] }}</td>
                    <td>{{ $money($day['gross_revenue']) }}</td>
                    <td>{{ $money($day['refunds']) }}</td>
                    <td>{{ $money($day['net_revenue']) }}</td>
                    <td>{{ $money($day['credit_card']) }}</td>
                    <td>{{ $money($day['apple_pay']) }}</td>
                    <td>{{ $money($day['pay_on_site_collected']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
