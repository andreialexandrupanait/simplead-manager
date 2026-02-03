@php $b = $data['backups']; @endphp

<h2>Copii de rezervă</h2>

{{-- Summary badges --}}
<table class="update-badges mb-6">
    <tr>
        <td class="update-badge">
            <div class="update-badge-label">Activat</div>
            <div class="update-badge-value">
                <span class="badge {{ $b['schedule_enabled'] ? 'badge-success' : 'badge-warning' }}">
                    {{ $b['schedule_enabled'] ? 'DA' : 'NU' }}
                </span>
            </div>
        </td>
        <td class="update-badge">
            <div class="update-badge-label">Periodicitate</div>
            <div class="update-badge-value" style="font-size: 14px;">{{ ucfirst($b['frequency']) }}</div>
        </td>
        <td class="update-badge">
            <div class="update-badge-label">Efectuate</div>
            <div class="update-badge-value">{{ $b['count'] }}</div>
        </td>
    </tr>
</table>

@if(count($b['backups'] ?? []) > 0)
    <h3>Istoric backup-uri</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Dată</th>
                <th>Tip</th>
                <th>Dimensiune</th>
                <th>Declanșator</th>
            </tr>
        </thead>
        <tbody>
            @foreach($b['backups'] as $backup)
                <tr>
                    <td>{{ $backup['created_at'] }}</td>
                    <td>{{ ucfirst($backup['type']) }}</td>
                    <td>{{ $backup['file_size'] }}</td>
                    <td>
                        <span class="badge badge-info">{{ ucfirst($backup['trigger']) }}</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="text-sm text-muted mt-4">
        Dimensiune totală: <strong>{{ $b['total_size'] }}</strong>
    </div>
@else
    <div style="text-align: center; padding: 20px; color: #6b7280;">
        Nu au fost create copii de rezervă în această perioadă.
    </div>
@endif
