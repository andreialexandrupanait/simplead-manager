@php $u = $data['updates']; @endphp

<h2>Actualizări</h2>

{{-- Summary badges --}}
<table class="update-badges mb-6">
    <tr>
        <td class="update-badge">
            <div class="update-badge-label">Plugin-uri</div>
            <div class="update-badge-value">{{ count($u['plugin_updates'] ?? []) }}</div>
        </td>
        <td class="update-badge">
            <div class="update-badge-label">Teme</div>
            <div class="update-badge-value">{{ count($u['theme_updates'] ?? []) }}</div>
        </td>
        <td class="update-badge">
            <div class="update-badge-label">WordPress</div>
            <div class="update-badge-value">{{ $u['wp_version'] ?? 'N/A' }}</div>
        </td>
    </tr>
</table>

@if(count($u['core_updates'] ?? []) > 0)
    <h3>Actualizări WordPress Core</h3>
    <table class="data-table mb-6">
        <thead>
            <tr>
                <th>Nume</th>
                <th>Dată</th>
                <th>Versiune</th>
                <th style="width: 40px; text-align: center;">Stare</th>
            </tr>
        </thead>
        <tbody>
            @foreach($u['core_updates'] as $update)
                <tr>
                    <td>WordPress Core</td>
                    <td>{{ \Carbon\Carbon::parse($update['performed_at'])->format('d/m/Y') }}</td>
                    <td>
                        {{ $update['from_version'] ?? '—' }}
                        <span class="version-arrow">&rarr;</span>
                        {{ $update['to_version'] ?? '—' }}
                    </td>
                    <td style="text-align: center;">
                        @if($update['success'])
                            <span class="check-success">&#10003;</span>
                        @else
                            <span class="text-danger">&#10007;</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if(count($u['plugin_updates'] ?? []) > 0)
    <h3>Actualizări Plugin-uri</h3>
    <table class="data-table mb-6">
        <thead>
            <tr>
                <th>Nume</th>
                <th>Dată</th>
                <th>Versiune</th>
                <th style="width: 40px; text-align: center;">Stare</th>
            </tr>
        </thead>
        <tbody>
            @foreach($u['plugin_updates'] as $update)
                <tr>
                    <td>{{ $update['name'] ?? $update['slug'] ?? '—' }}</td>
                    <td>{{ \Carbon\Carbon::parse($update['performed_at'])->format('d/m/Y') }}</td>
                    <td>
                        {{ $update['from_version'] ?? '—' }}
                        <span class="version-arrow">&rarr;</span>
                        {{ $update['to_version'] ?? '—' }}
                    </td>
                    <td style="text-align: center;">
                        @if($update['success'])
                            <span class="check-success">&#10003;</span>
                        @else
                            <span class="text-danger">&#10007;</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if(count($u['theme_updates'] ?? []) > 0)
    <h3>Actualizări Teme</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Nume</th>
                <th>Dată</th>
                <th>Versiune</th>
                <th style="width: 40px; text-align: center;">Stare</th>
            </tr>
        </thead>
        <tbody>
            @foreach($u['theme_updates'] as $update)
                <tr>
                    <td>{{ $update['name'] ?? $update['slug'] ?? '—' }}</td>
                    <td>{{ \Carbon\Carbon::parse($update['performed_at'])->format('d/m/Y') }}</td>
                    <td>
                        {{ $update['from_version'] ?? '—' }}
                        <span class="version-arrow">&rarr;</span>
                        {{ $update['to_version'] ?? '—' }}
                    </td>
                    <td style="text-align: center;">
                        @if($update['success'])
                            <span class="check-success">&#10003;</span>
                        @else
                            <span class="text-danger">&#10007;</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if($u['total_count'] === 0)
    <div style="text-align: center; padding: 30px; color: #6b7280;">
        Nu au fost efectuate actualizări în această perioadă.
    </div>
@endif
