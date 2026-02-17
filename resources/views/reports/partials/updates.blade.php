@php
    $u = $data['updates'];
    $lang = $language ?? 'ro';
@endphp

@include('reports.components.section-header', [
    'title' => __('report.section_updates', [], $lang),
])

@if($u['total_count'] === 0)
    <p style="color: #94a3b8; font-size: 8.5pt;">{{ __('report.updates_no_updates', [], $lang) }}</p>
@else
    {{-- WP Core status line --}}
    <p style="font-size: 8.5pt; color: #334155; margin-bottom: 10px;">
        <span class="check-success">&#10003;</span>
        {{ __('report.updates_wp_latest', [], $lang) }} (v{{ $u['wp_version'] ?? '—' }})
    </p>

    {{-- Summary text line --}}
    <p style="font-size: 8.5pt; color: #64748b; margin-bottom: 14px;">
        {{ __('report.updates_summary_line', [
            'total' => $u['total_count'],
            'plugins' => $u['plugin_count'],
            'themes' => $u['theme_count'],
            'core' => $u['core_count'],
        ], $lang) }}
    </p>

    {{-- Consolidated update table --}}
    @php
        $consolidated = $u['consolidated_updates'] ?? [];
        $totalConsolidated = count($consolidated);
        $displayUpdates = array_slice($consolidated, 0, 15);
    @endphp

    @if(count($displayUpdates) > 0)
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('report.updates_name', [], $lang) }}</th>
                    <th>{{ __('report.updates_type', [], $lang) }}</th>
                    <th>{{ __('report.updates_version', [], $lang) }}</th>
                    <th>{{ __('report.updates_date', [], $lang) }}</th>
                    <th>{{ __('report.updates_status', [], $lang) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($displayUpdates as $update)
                    <tr>
                        <td>{{ $update['name'] ?? '—' }}</td>
                        <td>
                            @php
                                $typeBadge = match($update['type'] ?? '') {
                                    'plugin' => 'badge-info',
                                    'theme' => 'badge-warning',
                                    'core' => 'badge-success',
                                    default => 'badge-info',
                                };
                            @endphp
                            <span class="badge {{ $typeBadge }}">{{ ucfirst($update['type'] ?? '—') }}</span>
                        </td>
                        <td>
                            {{ $update['from_version'] ?? '—' }}
                            <span class="version-arrow">&rarr;</span>
                            <span class="version-new">{{ $update['to_version'] ?? '—' }}</span>
                        </td>
                        <td>{{ isset($update['performed_at']) ? \Carbon\Carbon::parse($update['performed_at'])->format('d/m/Y') : '—' }}</td>
                        <td style="text-align: center;">
                            @if($update['success'] ?? true)
                                <span style="color: #10b981; font-weight: 700;">&#10003;</span>
                            @else
                                <span style="color: #ef4444; font-weight: 700;">&#10007;</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($totalConsolidated > 15)
            <div class="table-footnote">{{ __('report.showing_of', ['shown' => 15, 'total' => $totalConsolidated], $lang) }}</div>
        @endif
    @endif
@endif
