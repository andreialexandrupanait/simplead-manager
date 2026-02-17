@php
    $b = $data['backups'];
    $lang = $language ?? 'ro';
    $totalBackups = ($b['count'] ?? 0) + ($b['failed_count'] ?? 0);
@endphp

@include('reports.components.section-header', [
    'title' => __('report.section_backups', [], $lang),
])

@if($totalBackups === 0 && !($b['schedule_enabled'] ?? false))
    <p style="color: #94a3b8; font-size: 8.5pt;">
        <span class="badge badge-warning">{{ __('report.backups_disabled', [], $lang) }}</span>
        &nbsp; {{ __('report.backups_no_backups', [], $lang) }}
    </p>
@elseif($totalBackups === 0)
    <p style="color: #94a3b8; font-size: 8.5pt;">{{ __('report.backups_no_backups', [], $lang) }}</p>
@else
    {{-- Info card with key-value rows --}}
    <div class="highlight-box mb-4">
        <table class="info-row">
            <tr>
                <td class="info-label">{{ __('report.backups_status', [], $lang) }}</td>
                <td class="info-value">
                    <span class="badge {{ $b['schedule_enabled'] ? 'badge-success' : 'badge-warning' }}">
                        {{ $b['schedule_enabled'] ? __('report.backups_enabled', [], $lang) : __('report.backups_disabled', [], $lang) }}
                    </span>
                </td>
            </tr>
            <tr>
                <td class="info-label">{{ __('report.backups_frequency', [], $lang) }}</td>
                <td class="info-value">{{ ucfirst($b['frequency']) }}</td>
            </tr>
            <tr>
                <td class="info-label">{{ __('report.backups_successful', [], $lang) }}</td>
                <td class="info-value">{{ $b['count'] }}</td>
            </tr>
            <tr>
                <td class="info-label">{{ __('report.backups_failed', [], $lang) }}</td>
                <td class="info-value">{{ $b['failed_count'] ?? 0 }}</td>
            </tr>
            <tr>
                <td class="info-label">{{ __('report.backups_total_stored', [], $lang) }}</td>
                <td class="info-value">{{ $b['total_size'] }}</td>
            </tr>
        </table>
    </div>

    {{-- History table (max 8 rows) --}}
    @php
        $backupList = $b['backups'] ?? [];
        $totalHistory = count($backupList);
        $displayBackups = array_slice($backupList, 0, 8);
    @endphp

    @if(count($displayBackups) > 0)
        <h3>{{ __('report.backups_history', [], $lang) }}</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('report.backups_date', [], $lang) }}</th>
                    <th>{{ __('report.backups_type', [], $lang) }}</th>
                    <th>{{ __('report.backups_status', [], $lang) }}</th>
                    <th>{{ __('report.backups_size', [], $lang) }}</th>
                    <th>{{ __('report.backups_destination', [], $lang) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($displayBackups as $backup)
                    <tr>
                        <td>{{ isset($backup['created_at']) ? \Carbon\Carbon::parse($backup['created_at'])->format('d/m/Y') : '—' }}</td>
                        <td>{{ ucfirst($backup['type'] ?? '—') }}</td>
                        <td>
                            @php
                                $statusBadge = match(strtolower($backup['status'] ?? '')) {
                                    'completed', 'success' => 'badge-success',
                                    'failed', 'error' => 'badge-danger',
                                    default => 'badge-info',
                                };
                            @endphp
                            <span class="badge {{ $statusBadge }}">{{ ucfirst($backup['status'] ?? '—') }}</span>
                        </td>
                        <td>{{ $backup['file_size'] ?? '—' }}</td>
                        <td>{{ $backup['destination'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($totalHistory > 8)
            <div class="table-footnote">{{ __('report.showing_of', ['shown' => 8, 'total' => $totalHistory], $lang) }}</div>
        @endif
    @endif
@endif
