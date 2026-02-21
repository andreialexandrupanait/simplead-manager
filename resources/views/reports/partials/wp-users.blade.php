@php
    $wu = $data['wp_users'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['wp_users']['title'] ?? __('report.section_wp_users', [], $lang),
    'number' => $sectionNumber ?? null,
])

<p class="section-description">
    {{ $sectionOverrides['wp_users']['description'] ?? __('report.wp_users_description', [], $lang) }}
</p>

{{-- KPI Row --}}
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-value">{{ $wu['total_users'] }}</div>
        <div class="kpi-label">{{ __('report.wp_total_users', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value">{{ $wu['administrators'] }}</div>
        <div class="kpi-label">{{ __('report.wp_administrators', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value">{{ $wu['recent_logins'] }}</div>
        <div class="kpi-label">{{ __('report.wp_recent_logins', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value">{{ $wu['never_logged_in'] }}</div>
        <div class="kpi-label">{{ __('report.wp_never_logged_in', [], $lang) }}</div>
    </div>
</div>

{{-- Users by role chart (only show if more than one role) --}}
@if(!empty($wu['role_bar_chart']['bars']) && count($wu['role_bar_chart']['bars']) > 1)
    <div class="chart-container">
        <div class="chart-title">{{ __('report.wp_users_by_role', [], $lang) }}</div>
        @include('reports.components.chart-horizontal-bar', ['chartData' => $wu['role_bar_chart'], 'primaryColor' => $branding['primary_color'] ?? '#7C3AED'])
    </div>
@endif

{{-- Users table --}}
@if(!empty($wu['user_list']))
    <h3>{{ __('report.wp_user_list', [], $lang) }}</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('report.wp_username', [], $lang) }}</th>
                <th>{{ __('report.wp_email', [], $lang) }}</th>
                <th>{{ __('report.wp_role', [], $lang) }}</th>
                <th>{{ __('report.wp_last_login', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($wu['user_list'] as $user)
                <tr>
                    <td>{{ $user['username'] }}</td>
                    <td class="cell-break">{{ $user['email'] }}</td>
                    <td>{{ $user['role'] }}</td>
                    <td>{{ $user['last_login_at'] ?? __('report.wp_never', [], $lang) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
