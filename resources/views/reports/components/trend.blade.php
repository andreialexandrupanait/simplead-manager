{{-- Trend indicator: receives $trend array with direction, display, color, value --}}
@if(isset($trend) && ($trend['value'] ?? null) !== null)
    <span style="font-size: 9pt; font-weight: bold; color: {{ $trend['color'] }};">
        {{ $trend['display'] }}
        @if($showLabel ?? false)
            <span style="font-weight: normal; color: #9ca3af;"> vs {{ $vsLabel ?? __('report.vs_previous', [], $language ?? 'ro') }}</span>
        @endif
    </span>
@else
    {{-- No trend data — render nothing --}}
@endif
