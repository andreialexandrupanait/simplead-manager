@php
    $t = $data['tasks'] ?? [];
    $lang = $language ?? 'ro';
    $rows = $t['listed'] ?? [];
    $count = (int) ($t['completed_count'] ?? 0);
@endphp

@include('reports.components.section-header', [
    'title' => $sectionOverrides['tasks']['title'] ?? __('report.section_tasks', [], $lang),
    'number' => $sectionNumber ?? null,
])

@if($count === 0)
    <p style="color: #94a3b8; font-size: 8.5pt;">{{ __('report.tasks_none', [], $lang) }}</p>
@else
    <p style="font-size: 9pt; margin-bottom: 10px;">
        <strong>{{ $count }}</strong> {{ trans_choice('report.tasks_completed_count', $count, [], $lang) }}
        <span style="color: #94a3b8;">({{ $t['period_label'] ?? '' }})</span>
    </p>

    <table class="data-table" style="width: 100%;">
        <thead>
            <tr>
                <th style="text-align: left;">{{ __('report.tasks_name', [], $lang) }}</th>
                <th style="text-align: left; width: 22%;">{{ __('report.tasks_assignee', [], $lang) }}</th>
                <th style="text-align: right; width: 18%;">{{ __('report.tasks_completed_at', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td style="color: #64748b;">{{ $row['assignee'] ?? '—' }}</td>
                    <td style="text-align: right; color: #64748b;">{{ $row['completed_at'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(($t['truncated'] ?? 0) > 0)
        <p style="color: #94a3b8; font-size: 8pt; margin-top: 6px;">
            {{ __('report.tasks_truncated', ['n' => $t['truncated']], $lang) }}
        </p>
    @endif
@endif
