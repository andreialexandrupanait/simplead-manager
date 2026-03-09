{{--
    Reusable metric card (used as <td> inside a table row).
    Receives: $label, $value, $sublabel (optional), $trend (optional), $icon (optional)
--}}
<td class="overview-card" style="width: {{ $width ?? '33%' }};">
    @if(isset($icon))
        <div style="font-size: 14pt; margin-bottom: 4px;">{{ $icon }}</div>
    @endif
    <div class="card-label">{{ $label }}</div>
    <div class="{{ $valueClass ?? 'card-value' }}">{{ $value }}</div>
    @if(isset($sublabel))
        <div class="card-sublabel">{{ $sublabel }}</div>
    @endif
    @if(isset($trend))
        <div class="card-trend">
            @include('reports.components.trend', ['trend' => $trend])
        </div>
    @endif
</td>
