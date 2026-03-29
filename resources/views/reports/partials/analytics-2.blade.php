@php
    $a = $data['analytics'];
    $lang = $language ?? 'ro';
@endphp

{{-- 2x2 grid: Traffic sources, Top pages, Top cities, Top countries --}}
<table class="quad-grid">
    <tr>
        {{-- Traffic sources --}}
        <td>
            <h3>{{ __('report.analytics_traffic_sources', [], $lang) }}</h3>
            @if(isset($a['traffic_sources']) && count($a['traffic_sources']) > 0)
                @php $totalSourceUsers = max(1, array_sum(array_column($a['traffic_sources'] ?? [], 'users'))); @endphp
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('report.analytics_channel', [], $lang) }}</th>
                            <th>{{ __('report.analytics_user_count', [], $lang) }}</th>
                            <th>{{ __('report.analytics_percent', [], $lang) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($a['traffic_sources'], 0, 6) as $source)
                            <tr>
                                <td>{{ $source['source'] ?? '—' }}</td>
                                <td>{{ number_format($source['users'] ?? 0) }}</td>
                                <td>{{ number_format((($source['users'] ?? 0) / $totalSourceUsers) * 100, 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
        {{-- Top pages --}}
        <td>
            <h3>{{ __('report.analytics_top_pages', [], $lang) }}</h3>
            @if(isset($a['top_pages']) && count($a['top_pages']) > 0)
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('report.analytics_page_col', [], $lang) }}</th>
                            <th>{{ __('report.analytics_views', [], $lang) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($a['top_pages'], 0, 6) as $page)
                            <tr>
                                <td style="word-break: break-all;">{{ $page['page'] ?? $page['path'] ?? '—' }}</td>
                                <td>{{ number_format($page['pageviews'] ?? $page['views'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
    </tr>
    <tr>
        {{-- Top cities --}}
        <td>
            <h3>{{ __('report.analytics_top_cities', [], $lang) }}</h3>
            @if(isset($a['cities']) && count($a['cities']) > 0)
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('report.analytics_city', [], $lang) }}</th>
                            <th>{{ __('report.analytics_user_count', [], $lang) }}</th>
                            <th>{{ __('report.analytics_sessions', [], $lang) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($a['cities'], 0, 6) as $city)
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
        {{-- Top countries --}}
        <td>
            <h3>{{ __('report.analytics_top_countries', [], $lang) }}</h3>
            @if(isset($a['countries']) && count($a['countries']) > 0)
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('report.analytics_country', [], $lang) }}</th>
                            <th>{{ __('report.analytics_user_count', [], $lang) }}</th>
                            <th>{{ __('report.analytics_sessions', [], $lang) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($a['countries'], 0, 6) as $country)
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
    </tr>
</table>
