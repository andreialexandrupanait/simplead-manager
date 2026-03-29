@php
    $sc = $data['search_console'];
    $lang = $language ?? 'ro';
@endphp

{{-- 2x2 grid: Top pages, Top countries, Devices, Top dates --}}
<table class="quad-grid">
    <tr>
        {{-- Top pages --}}
        <td>
            <h3>{{ __('report.search_top_pages', [], $lang) }}</h3>
            @if(isset($sc['pages']) && count($sc['pages']) > 0)
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('report.search_page', [], $lang) }}</th>
                            <th>{{ __('report.search_clicks', [], $lang) }}</th>
                            <th>{{ __('report.search_ctr', [], $lang) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($sc['pages'], 0, 5) as $page)
                            <tr>
                                <td style="word-break: break-all;">{{ $page['page'] ?? $page['keys'][0] ?? '—' }}</td>
                                <td>{{ number_format($page['clicks'] ?? 0) }}</td>
                                <td>{{ isset($page['ctr']) ? number_format($page['ctr'] * 100, 1) . '%' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
        {{-- Top countries --}}
        <td>
            <h3>{{ __('report.search_top_countries', [], $lang) }}</h3>
            @if(isset($sc['countries']) && count($sc['countries']) > 0)
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('report.search_country', [], $lang) }}</th>
                            <th>{{ __('report.search_clicks', [], $lang) }}</th>
                            <th>{{ __('report.search_impressions', [], $lang) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($sc['countries'], 0, 5) as $country)
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
    </tr>
    <tr>
        {{-- Devices --}}
        <td>
            <h3>{{ __('report.search_top_devices', [], $lang) }}</h3>
            @if(isset($sc['devices']) && count($sc['devices']) > 0)
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('report.search_device', [], $lang) }}</th>
                            <th>{{ __('report.search_clicks', [], $lang) }}</th>
                            <th>{{ __('report.search_impressions', [], $lang) }}</th>
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
        {{-- Top dates --}}
        <td>
            @if(isset($sc['overview']['daily_data']) && count($sc['overview']['daily_data']) > 0)
                <h3>{{ __('report.search_top_dates', [], $lang) }}</h3>
                @php
                    $topDates = collect($sc['overview']['daily_data'] ?? [])->sortByDesc('clicks')->take(5)->values();
                @endphp
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('report.search_date', [], $lang) }}</th>
                            <th>{{ __('report.search_clicks', [], $lang) }}</th>
                            <th>{{ __('report.search_impressions', [], $lang) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($topDates as $day)
                            <tr>
                                <td>{{ isset($day['date']) ? \Carbon\Carbon::parse($day['date'])->format('d/m/Y') : '—' }}</td>
                                <td>{{ number_format($day['clicks'] ?? 0) }}</td>
                                <td>{{ number_format($day['impressions'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
    </tr>
</table>
