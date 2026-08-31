{{-- $title, $generatedAt (ISO-8601 string) are always passed by the calling report view. $rangeFrom/$rangeTo
     (ISO-8601 strings) are optional - the Audit Log export has no mandatory date filter, so a report with no
     date bound on either side renders "All time" rather than a misleading fabricated window. --}}
<div class="report-header">
    <p class="report-title">BLUE — {{ $title }}</p>
    <p class="report-subtitle">
        Period:
        @if ($rangeFrom || $rangeTo)
            {{ $rangeFrom ? \Illuminate\Support\Carbon::parse($rangeFrom)->format('d M Y, H:i') : 'earliest' }}
            —
            {{ $rangeTo ? \Illuminate\Support\Carbon::parse($rangeTo)->format('d M Y, H:i') : 'latest' }} (UTC)
        @else
            All time
        @endif
    </p>
    <p class="report-meta">Generated {{ \Illuminate\Support\Carbon::parse($generatedAt)->format('d M Y, H:i') }} UTC</p>
</div>
