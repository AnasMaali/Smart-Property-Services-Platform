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
    'title' => 'Audit Log Export',
    'rangeFrom' => $rangeFrom,
    'rangeTo' => $rangeTo,
    'generatedAt' => $generatedAt,
])

<table class="summary-grid">
    <tr>
        <td><span class="label">Matching Entries</span><span class="value">{{ $export['summary']['total'] }}</span></td>
    </tr>
</table>

@if ($export['truncated'])
    <p class="truncation-note">Showing the first {{ $maxRows }} of {{ $export['total'] }} matching entries. Use the CSV export for the complete result set.</p>
@endif

@if (empty($export['rows']))
    <p class="empty-note">No audit log entries match these filters.</p>
@else
    <table class="report-table">
        <thead>
            <tr>
                <th>When</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Outcome</th>
                <th>Actor</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($export['rows'] as $row)
                <tr>
                    <td>{{ $fmt($row['created_at']) }}</td>
                    <td>{{ $row['action_code'] }}</td>
                    <td>{{ $row['entity_identifier'] ? $row['entity_type'].' '.$row['entity_identifier'] : $row['entity_type'] }}</td>
                    <td>{{ $row['was_successful'] ? 'Success' : 'Failed' }}</td>
                    <td>{{ $row['actor']['full_name'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
