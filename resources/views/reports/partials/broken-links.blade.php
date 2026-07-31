@php
    $b = $data['broken_links'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['broken_links']['title'] ?? __('report.section_broken_links', [], $lang),
    'number' => $sectionNumber ?? null,
])

{{-- The headline is the CHANGE, not the state (SPEC §5.7): a list of forty broken
     links, identical month after month, is never read. --}}
<p style="font-size: 9pt; color: #334155; margin-bottom: 12px;">
    @if($b['unchanged'])
        {{ __('report.broken_links_no_change', [], $lang) }}
    @else
        {{ __('report.broken_links_change', [
            'new' => $b['new_broken_count'],
            'resolved' => $b['resolved_count'],
        ], $lang) }}
    @endif
</p>

<div class="kpi-row mb-4">
    <div class="kpi-card">
        <div class="kpi-value {{ $b['broken_count'] == 0 ? 'value-muted' : '' }}">{{ $b['broken_count'] }}</div>
        <div class="kpi-label">{{ __('report.broken_links_broken', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value value-muted">{{ $b['total_urls'] }}</div>
        <div class="kpi-label">{{ __('report.broken_links_checked', [], $lang) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value {{ $b['chains_count'] == 0 ? 'value-muted' : '' }}">{{ $b['chains_count'] }}</div>
        <div class="kpi-label">{{ __('report.broken_links_chains', [], $lang) }}</div>
    </div>
</div>

@if(!empty($b['listed']))
    <table class="data-table">
        <thead>
            <tr>
                <th>{{ __('report.broken_links_url', [], $lang) }}</th>
                <th>{{ __('report.broken_links_found_on', [], $lang) }}</th>
                <th>{{ __('report.broken_links_status', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($b['listed'] as $link)
                <tr>
                    <td style="word-break: break-all;">{{ $link['url'] }}</td>
                    <td style="word-break: break-all; color: #64748b;">{{ $link['source_page'] ?? '—' }}</td>
                    <td>{{ $link['status_code'] ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($b['listed_of'] > count($b['listed']))
        <div class="table-footnote">
            {{ __('report.showing_of', ['shown' => count($b['listed']), 'total' => $b['listed_of']], $lang) }}
        </div>
    @endif
@endif
