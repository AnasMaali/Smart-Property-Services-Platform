{{--
    BLUE V1 Service Completion Report - the customer-facing PDF App\Actions\
    Admin\Booking\GenerateAdminBookingCompletionReportAction renders via
    App\Support\Admin\Reports\AdminReportPdf::render(). $booking is the exact
    App\Support\Admin\AdminBookingPresenter::detail() shape the Admin
    Booking detail page already renders - no field here is recomputed.
    $beforeImages/$afterImages are already-inlined `data:image/jpeg;
    base64,...` URIs (App\Support\Admin\Reports\
    AdminBookingCompletionReportPhotoProcessor) - dompdf never fetches a
    remote asset (isRemoteEnabled is false).

    Every piece of Admin/customer-supplied free text ($completionNote,
    location fields, service names, technician names) is rendered through
    plain Blade `{{ }}` escaping - never `{!! !!}` - so nothing here can
    execute as HTML. No internal database UUID, audit id, or provider
    payload is ever printed - only the human-readable Booking Number.
--}}
@php
    $fmt = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y, H:i') : '—';
    $fmtDate = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d M Y') : '—';
    $money = function ($amount) use ($booking) {
        if ($amount === null) {
            return '—';
        }
        $currency = $booking['currency'];
        $decimals = $currency['decimal_places'] ?? 2;
        $formatted = number_format((float) $amount, $decimals);

        return $currency && ($currency['symbol'] ?? null) ? "{$currency['symbol']} {$formatted}" : trim("{$formatted} ".($currency['code'] ?? ''));
    };
    $label = fn (?string $code) => $code ? ucwords(strtolower(str_replace('_', ' ', $code))) : '—';

    $location = $booking['location'];
    $addressParts = array_filter([
        $location['building_name_or_number'] ?? null,
        $location['street_name'] ?? null,
        $location['area_name'] ?? null,
        $location['city_name'] ?? null,
    ], fn ($part) => $part !== null && $part !== '');

    $technicianNames = collect($booking['items'])
        ->map(fn ($item) => $item['active_assignment']['technician']['full_name'] ?? null)
        ->filter()
        ->unique()
        ->values();

    $inspectionQuotes = collect($booking['items'])
        ->map(fn ($item) => [$item, $item['inspection_quote']['quote'] ?? null])
        ->filter(fn ($pair) => $pair[1] !== null)
        ->values();
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #0f172a; margin: 0; padding: 24px; }

        .report-header { display: table; width: 100%; border-bottom: 2px solid #0f172a; padding-bottom: 10px; margin-bottom: 16px; }
        .report-header-left { display: table-cell; vertical-align: bottom; }
        .report-header-right { display: table-cell; vertical-align: bottom; text-align: right; }
        .brand { font-size: 20px; font-weight: bold; letter-spacing: 1px; margin: 0; }
        .report-title { font-size: 14px; font-weight: bold; margin: 2px 0 0; color: #1e40af; }
        .booking-number { font-size: 12px; margin-top: 4px; }
        .status-badge { display: inline-block; background: #16a34a; color: #ffffff; font-size: 10px; font-weight: bold; text-transform: uppercase; padding: 4px 10px; border-radius: 10px; }
        .report-meta { font-size: 8px; color: #64748b; margin-top: 4px; }

        .cards { display: table; width: 100%; margin-bottom: 14px; }
        .card { display: table-cell; width: 50%; vertical-align: top; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 4px; }
        .card + .card { padding-left: 12px; }
        .card-title { font-size: 8px; text-transform: uppercase; color: #64748b; font-weight: bold; margin: 0 0 6px; }
        .card p { margin: 2px 0; font-size: 10px; }

        .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #0f172a; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin: 16px 0 8px; }

        table.items-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.items-table th { background: #0f172a; color: #ffffff; text-align: left; padding: 5px 6px; font-size: 8px; text-transform: uppercase; }
        table.items-table td { border-bottom: 1px solid #e2e8f0; padding: 5px 6px; font-size: 9px; vertical-align: top; }
        table.items-table tr:nth-child(even) td { background: #f8fafc; }
        .item-options { font-size: 8px; color: #64748b; margin-top: 2px; }

        table.summary-table { width: 100%; border-collapse: collapse; }
        table.summary-table td { padding: 4px 6px; font-size: 10px; border-bottom: 1px solid #e2e8f0; }
        table.summary-table td.label-cell { color: #64748b; width: 55%; }
        table.summary-table td.value-cell { font-weight: bold; text-align: right; }

        .note-box { border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px 12px; font-size: 10px; white-space: pre-wrap; }

        .photo-grid { display: table; width: 100%; }
        .photo-row { display: table-row; }
        .photo-cell { display: table-cell; width: 25%; padding: 4px; text-align: center; vertical-align: top; }
        .photo-cell img { width: 100%; height: auto; border: 1px solid #cbd5e1; border-radius: 3px; }
        .photo-caption { font-size: 8px; color: #64748b; margin-top: 2px; }

        .report-footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #cbd5e1; text-align: center; font-size: 9px; color: #64748b; }
        .empty-note { color: #64748b; font-size: 9px; }
    </style>
</head>
<body>

<div class="report-header">
    <div class="report-header-left">
        <p class="brand">BLUE</p>
        <p class="report-title">Service Completion Report</p>
        <p class="booking-number">Booking {{ $booking['booking_number'] }}</p>
    </div>
    <div class="report-header-right">
        <span class="status-badge">Completed</span>
        <p class="report-meta">Service completed {{ $fmtDate($booking['completed_at']) }}</p>
        <p class="report-meta">Generated {{ $fmt($generatedAt) }} UTC</p>
    </div>
</div>

<div class="cards">
    <div class="card">
        <p class="card-title">Customer</p>
        <p><strong>{{ $booking['customer']['full_name'] ?? '—' }}</strong></p>
        <p>{{ $booking['customer']['phone_number'] ?? '—' }}</p>
    </div>
    <div class="card">
        <p class="card-title">Property</p>
        <p>{{ $location['property_type_name'] ?? 'Address on file' }}</p>
        <p>{{ $addressParts !== [] ? implode(', ', $addressParts) : '—' }}</p>
        @if(($location['unit_number'] ?? '') !== '' || ($location['floor_number'] ?? '') !== '')
            <p>
                @if(($location['floor_number'] ?? '') !== '') Floor {{ $location['floor_number'] }} @endif
                @if(($location['unit_number'] ?? '') !== '') Unit {{ $location['unit_number'] }} @endif
            </p>
        @endif
    </div>
</div>

<div class="cards">
    <div class="card">
        <p class="card-title">Booking</p>
        <p>Appointment: {{ $fmt($booking['appointment']['slot']['starts_at'] ?? null) }}</p>
        <p>Created: {{ $fmtDate($booking['created_at']) }}</p>
    </div>
    <div class="card">
        <p class="card-title">Technician{{ $technicianNames->count() === 1 ? '' : 's' }}</p>
        @forelse($technicianNames as $name)
            <p>{{ $name }}</p>
        @empty
            <p class="empty-note">Not recorded</p>
        @endforelse
    </div>
</div>

<p class="section-title">Service Details</p>
<table class="items-table">
    <thead>
        <tr>
            <th>Service</th>
            <th>Qty</th>
            <th>Status</th>
            <th style="text-align: right;">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($booking['items'] as $item)
            <tr>
                <td>
                    {{ $item['service']['name'] }}
                    @php
                        $extras = array_merge(
                            array_column($item['selected_choices'], 'choice_name'),
                            collect($item['selected_options'])->map(fn ($o) => $o['option_name'])->all(),
                        );
                    @endphp
                    @if($extras !== [])
                        <div class="item-options">{{ implode(', ', $extras) }}</div>
                    @endif
                </td>
                <td>{{ $item['quantity'] }}</td>
                <td>{{ $label($item['status']) }}</td>
                <td style="text-align: right;">{{ $money($item['pricing']['line_total']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p class="section-title">Payment Summary</p>
<table class="summary-table">
    @if($booking['payment'])
        <tr><td class="label-cell">Payment method</td><td class="value-cell">{{ $label($booking['payment_method']) }}</td></tr>
        <tr><td class="label-cell">Amount paid</td><td class="value-cell">{{ $money($booking['payment']['amount']) }}</td></tr>
        <tr><td class="label-cell">Status</td><td class="value-cell">{{ $label($booking['payment']['status']) }}</td></tr>
    @elseif($booking['on_site_settlement'])
        <tr><td class="label-cell">Payment method</td><td class="value-cell">Pay on site</td></tr>
        <tr><td class="label-cell">Amount due</td><td class="value-cell">{{ $money($booking['on_site_settlement']['amount_due']) }}</td></tr>
        <tr>
            <td class="label-cell">Collection status</td>
            <td class="value-cell">{{ $label($booking['on_site_settlement']['collection_status']) }}</td>
        </tr>
        @if($booking['on_site_settlement']['amount_collected'] !== null)
            <tr><td class="label-cell">Amount collected</td><td class="value-cell">{{ $money($booking['on_site_settlement']['amount_collected']) }}</td></tr>
        @endif
    @else
        <tr><td class="label-cell">Payment method</td><td class="value-cell">Covered by service contract</td></tr>
    @endif
    <tr><td class="label-cell">Total</td><td class="value-cell">{{ $money($booking['total']) }}</td></tr>
</table>

@if($inspectionQuotes->isNotEmpty())
    <p class="section-title">Inspection &amp; Final Quote</p>
    @foreach($inspectionQuotes as [$item, $quote])
        <table class="summary-table" style="margin-bottom: 8px;">
            <tr><td class="label-cell">Service</td><td class="value-cell">{{ $item['service']['name'] }}</td></tr>
            <tr><td class="label-cell">Final quoted amount</td><td class="value-cell">{{ $money($quote['quoted_amount']) }}</td></tr>
            <tr><td class="label-cell">Inspection credit applied</td><td class="value-cell">{{ $money($quote['credit_amount']) }}</td></tr>
            <tr><td class="label-cell">Remaining balance</td><td class="value-cell">{{ $money($quote['balance_due_amount']) }}</td></tr>
            <tr><td class="label-cell">Quote status</td><td class="value-cell">{{ $label($quote['status']) }}</td></tr>
        </table>
    @endforeach
@endif

@if($completionNote)
    <p class="section-title">Completion Note</p>
    <div class="note-box">{{ $completionNote }}</div>
@endif

@if(!empty($beforeImages))
    <p class="section-title">Before Photos</p>
    <div class="photo-grid">
        <div class="photo-row">
            @foreach($beforeImages as $index => $dataUri)
                <div class="photo-cell">
                    <img src="{{ $dataUri }}" alt="Before {{ $index + 1 }}">
                    <p class="photo-caption">Before {{ $index + 1 }}</p>
                </div>
                @if(($index + 1) % 4 === 0 && ! $loop->last)
                    </div><div class="photo-row">
                @endif
            @endforeach
        </div>
    </div>
@endif

@if(!empty($afterImages))
    <p class="section-title">After Photos</p>
    <div class="photo-grid">
        <div class="photo-row">
            @foreach($afterImages as $index => $dataUri)
                <div class="photo-cell">
                    <img src="{{ $dataUri }}" alt="After {{ $index + 1 }}">
                    <p class="photo-caption">After {{ $index + 1 }}</p>
                </div>
                @if(($index + 1) % 4 === 0 && ! $loop->last)
                    </div><div class="photo-row">
                @endif
            @endforeach
        </div>
    </div>
@endif

<div class="report-footer">
    <p>Thank you for choosing BLUE.</p>
    <p>Generated {{ $fmt($generatedAt) }} UTC</p>
</div>

</body>
</html>
