@php
    $db = $data['database'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => __('report.section_database', [], $lang),
])

{{-- Summary cards --}}
<table class="kpi-grid mb-4">
    <tr>
        <td class="kpi-card" style="width: 33%;">
            <div class="kpi-label">{{ __('report.database_optimized', [], $lang) }}</div>
            <div style="margin-top: 4px;">
                <span class="badge badge-success">{{ __('report.yes', [], $lang) }}</span>
            </div>
        </td>
        <td class="kpi-card" style="width: 33%;">
            <div class="kpi-label">{{ __('report.database_saved', [], $lang) }}</div>
            <div class="kpi-value" style="font-size: 14pt;">{{ \App\Helpers\FormatHelper::bytes($db['total_saved'] ?? 0) }}</div>
        </td>
        <td class="kpi-card" style="width: 33%;">
            <div class="kpi-label">{{ __('report.database_last_cleanup', [], $lang) }}</div>
            <div class="kpi-value" style="font-size: 12pt;">
                {{ $db['last_cleanup_date'] ? \Carbon\Carbon::parse($db['last_cleanup_date'])->format('d/m/Y') : '—' }}
            </div>
        </td>
    </tr>
</table>

{{-- Cleanup detail table --}}
<h3>{{ __('report.database_cleanup_title', [], $lang) }}</h3>
<table class="data-table">
    <thead>
        <tr>
            <th>{{ __('report.database_category', [], $lang) }}</th>
            <th style="text-align: right;">{{ __('report.database_deleted', [], $lang) }}</th>
            <th style="text-align: right;">{{ __('report.database_space_saved', [], $lang) }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($db['categories'] ?? [] as $cat)
            @if(($cat['deleted'] ?? 0) > 0 || ($cat['saved'] ?? 0) > 0)
                <tr>
                    <td>{{ __('report.database_' . $cat['key'], [], $lang) }}</td>
                    <td style="text-align: right;">{{ number_format($cat['deleted'] ?? 0) }}</td>
                    <td style="text-align: right;">{{ \App\Helpers\FormatHelper::bytes($cat['saved'] ?? 0) }}</td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>

<div class="text-sm mt-4" style="font-weight: 600; color: #111827;">
    {{ __('report.database_total_saved', [], $lang) }}: {{ \App\Helpers\FormatHelper::bytes($db['total_saved'] ?? 0) }}
</div>
