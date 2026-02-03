@php $sc = $data['search_console']; @endphp

<h2>Google Console de Căutare (continuare)</h2>

@if(isset($sc['pages']) && is_array($sc['pages']) && count($sc['pages']) > 0)
    <h3>Top pagini</h3>
    <table class="data-table mb-6">
        <thead>
            <tr>
                <th>Pagină</th>
                <th>Clicuri</th>
                <th>Impresii</th>
                <th>CTR</th>
            </tr>
        </thead>
        <tbody>
            @foreach(array_slice($sc['pages'], 0, 10) as $page)
                <tr>
                    <td style="word-break: break-all; font-size: 8px;">{{ $page['page'] ?? $page['keys'][0] ?? '—' }}</td>
                    <td>{{ number_format($page['clicks'] ?? 0) }}</td>
                    <td>{{ number_format($page['impressions'] ?? 0) }}</td>
                    <td>{{ isset($page['ctr']) ? number_format($page['ctr'] * 100, 1) . '%' : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<table class="two-col">
    <tr>
        <td>
            @if(isset($sc['countries']) && is_array($sc['countries']) && count($sc['countries']) > 0)
                <h3>Top țări</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Țară</th>
                            <th>Clicuri</th>
                            <th>Impresii</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($sc['countries'], 0, 8) as $country)
                            <tr>
                                <td>{{ $country['country'] ?? $country['keys'][0] ?? '—' }}</td>
                                <td>{{ number_format($country['clicks'] ?? 0) }}</td>
                                <td>{{ number_format($country['impressions'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
        <td>
            @if(isset($sc['devices']) && is_array($sc['devices']) && count($sc['devices']) > 0)
                <h3>Dispozitive</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Dispozitiv</th>
                            <th>Clicuri</th>
                            <th>Impresii</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sc['devices'] as $device)
                            <tr>
                                <td>{{ ucfirst($device['device'] ?? $device['keys'][0] ?? '—') }}</td>
                                <td>{{ number_format($device['clicks'] ?? 0) }}</td>
                                <td>{{ number_format($device['impressions'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
    </tr>
</table>
