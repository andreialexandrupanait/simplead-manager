@php $a = $data['analytics']; @endphp

<h2>Analitică (continuare)</h2>

@if(isset($a['traffic_sources']) && is_array($a['traffic_sources']) && count($a['traffic_sources']) > 0)
    <h3>Surse de trafic</h3>
    <table class="data-table mb-6">
        <thead>
            <tr>
                <th>Sursă / Mediu</th>
                <th>Utilizatori</th>
                <th>Sesiuni</th>
                <th>Procent</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalSourceUsers = max(1, array_sum(array_column($a['traffic_sources'], 'users')));
            @endphp
            @foreach(array_slice($a['traffic_sources'], 0, 10) as $source)
                <tr>
                    <td>{{ $source['source'] ?? $source['name'] ?? '—' }}</td>
                    <td>{{ number_format($source['users'] ?? 0) }}</td>
                    <td>{{ number_format($source['sessions'] ?? 0) }}</td>
                    <td>{{ number_format((($source['users'] ?? 0) / $totalSourceUsers) * 100, 1) }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if(isset($a['top_pages']) && is_array($a['top_pages']) && count($a['top_pages']) > 0)
    <h3>Top pagini</h3>
    <table class="data-table mb-6">
        <thead>
            <tr>
                <th>Pagină</th>
                <th>Vizualizări</th>
                <th>Utilizatori</th>
            </tr>
        </thead>
        <tbody>
            @foreach(array_slice($a['top_pages'], 0, 15) as $page)
                <tr>
                    <td style="word-break: break-all;">{{ $page['page'] ?? $page['path'] ?? '—' }}</td>
                    <td>{{ number_format($page['pageviews'] ?? $page['views'] ?? 0) }}</td>
                    <td>{{ number_format($page['users'] ?? 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<table class="two-col">
    <tr>
        <td>
            @if(isset($a['countries']) && is_array($a['countries']) && count($a['countries']) > 0)
                <h3>Top țări</h3>
                <table class="data-table mb-6">
                    <thead>
                        <tr>
                            <th>Țară</th>
                            <th>Utilizatori</th>
                            <th>Sesiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($a['countries'], 0, 10) as $country)
                            <tr>
                                <td>{{ $country['country'] ?? $country['name'] ?? '—' }}</td>
                                <td>{{ number_format($country['users'] ?? 0) }}</td>
                                <td>{{ number_format($country['sessions'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
        <td>
            @if(isset($a['cities']) && is_array($a['cities']) && count($a['cities']) > 0)
                <h3>Top orașe</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Oraș</th>
                            <th>Utilizatori</th>
                            <th>Sesiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($a['cities'], 0, 10) as $city)
                            <tr>
                                <td>{{ $city['city'] ?? $city['name'] ?? '—' }}</td>
                                <td>{{ number_format($city['users'] ?? 0) }}</td>
                                <td>{{ number_format($city['sessions'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
    </tr>
</table>
