@php $l = $data['links']; @endphp

<h2>Link-uri verificate</h2>

{{-- Metric cards --}}
<table class="links-metrics mb-6">
    <tr>
        <td class="links-metric-card">
            <div class="links-metric-value">{{ $l['total_links'] ?? 0 }}</div>
            <div class="links-metric-label">Link-uri verificate</div>
        </td>
        <td class="links-metric-card">
            <div class="links-metric-value" style="color: {{ ($l['broken_links'] ?? 0) > 0 ? '#dc2626' : '#16a34a' }};">{{ $l['broken_links'] ?? 0 }}</div>
            <div class="links-metric-label">Link-uri rupte</div>
        </td>
        <td class="links-metric-card">
            <div class="links-metric-value">{{ $l['redirects'] ?? 0 }}</div>
            <div class="links-metric-label">Redirecționări</div>
        </td>
        <td class="links-metric-card">
            <div class="links-metric-value">{{ $l['pages_scanned'] ?? 0 }}</div>
            <div class="links-metric-label">Pagini scanate</div>
        </td>
    </tr>
</table>

@if(isset($l['scanned_at']))
    <div class="text-sm text-muted mb-4">Ultima scanare: {{ $l['scanned_at'] }}</div>
@endif

@if(count($l['broken_links_list'] ?? []) > 0)
    <h3>Link-uri rupte</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>URL rupt</th>
                <th style="width: 50px;">Cod</th>
                <th>Găsit pe</th>
            </tr>
        </thead>
        <tbody>
            @foreach($l['broken_links_list'] as $link)
                <tr>
                    <td style="word-break: break-all; font-size: 8px;">{{ $link['url'] }}</td>
                    <td>
                        <span class="badge badge-danger">{{ $link['http_code'] ?? '—' }}</span>
                    </td>
                    <td style="word-break: break-all; font-size: 8px;">{{ $link['source_url'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <div style="text-align: center; padding: 30px; color: #16a34a; font-weight: 600;">
        Niciun link rupt găsit.
    </div>
@endif
